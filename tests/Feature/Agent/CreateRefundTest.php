<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Application\UseCases\CreateRefund\CreateRefundUseCase;
use App\Application\UseCases\ExecuteRefund\ExecuteRefundUseCase;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\Enums\OrderStatus;
use App\Domain\Order\Exceptions\IdempotencyConflictException;
use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\ValueObjects\PaymentResult;
use App\Domain\Refund\Contracts\RefundRepositoryInterface;
use App\Domain\Refund\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class CreateRefundTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
        config([
            'services.agent.token' => 'test-token-not-secret',
            'services.agent.rate_limit_per_minute' => 600,
            'services.refunds.auto_approve_limit' => '50.00',
            'services.simulated_payment.refund_force_outcome' => 'success',
            'services.simulated_payment.timeout_after_persist' => false,
            'services.simulated_payment.after_persist_barrier' => null,
        ]);
    }

    #[Test]
    public function small_auto_refund_succeeds_and_is_readable(): void
    {
        $order = $this->createOrder();
        $headers = $this->headers($order) + ['X-Trace-Id' => 'create-refund-trace'];
        $response = $this->postJson($this->url($order), $this->body(), $headers)
            ->assertCreated()->assertJsonPath('data.amount', '20.00')
            ->assertJsonPath('data.status', 'SUCCEEDED')
            ->assertJsonPath('data.trace_id', 'create-refund-trace');
        $data = $response->json('data');

        $this->assertSame([
            'id', 'order_id', 'amount', 'reason', 'mode', 'status', 'decided_by',
            'decision_reason', 'failure_message', 'trace_id', 'created_at', 'updated_at',
        ], array_keys($data));
        $this->getJson('/api/agent/refunds/'.$data['id'], $headers)->assertOk()->assertExactJson(['data' => $data]);
        $this->getJson($this->url($order), $headers)->assertOk()->assertExactJson(['data' => [$data]]);
        $this->getJson('/api/agent/orders/'.$order->id, $headers)->assertOk()
            ->assertJsonPath('data.refunded_amount', '20.00')->assertJsonPath('data.reserved_amount', '20.00');
        $this->assertDatabaseHas('refunds', ['id' => $data['id'], 'customer_id' => $order->user_id]);
    }

    #[Test]
    public function exact_replay_returns_200_same_id_even_with_new_reason(): void
    {
        $order = $this->createOrder();
        $first = $this->postJson($this->url($order), $this->body(), $this->headers($order))->assertCreated();
        $this->postJson($this->url($order), $this->body(['reason' => 'A different reason.']), $this->headers($order))
            ->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('simulated_payment_operations', 1);
        $this->assertDatabaseHas('simulated_payment_operations', ['request_count' => 1, 'side_effect_count' => 1]);
    }

    #[Test]
    public function amount_30_and_30_00_are_the_same_request(): void
    {
        $order = $this->createOrder();
        $first = $this->postJson($this->url($order), $this->body(['amount' => '30']), $this->headers($order))->assertCreated();
        $this->postJson($this->url($order), $this->body(['amount' => '30.00']), $this->headers($order))
            ->assertOk()->assertExactJson($first->json())->assertJsonPath('data.amount', '30.00');
        $this->assertDatabaseCount('refunds', 1);
    }

    #[Test]
    public function key_reuse_with_different_amount_or_mode_is_409_without_data(): void
    {
        $order = $this->createOrder();
        $this->postJson($this->url($order), $this->body(), $this->headers($order))->assertCreated();

        foreach ([['amount' => '21.00'], ['mode' => 'needs_approval']] as $changes) {
            $response = $this->postJson($this->url($order), $this->body($changes), $this->headers($order))
                ->assertConflict()->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');
            $this->assertArrayNotHasKey('data', $response->json());
        }
        $otherOrder = $this->createOrder();
        $this->postJson($this->url($otherOrder), $this->body(), $this->headers($otherOrder))
            ->assertConflict()->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT')->assertJsonMissingPath('data');
        $this->assertDatabaseCount('refunds', 1);
    }

    #[Test]
    public function other_customer_gets_404_even_for_an_existing_key(): void
    {
        $order = $this->createOrder();
        $other = $this->createOrder();
        $existing = $this->postJson($this->url($order), $this->body(), $this->headers($order))->assertCreated();
        $missing = $this->postJson('/api/agent/orders/999999/refunds', $this->body(), $this->headers($other))->assertNotFound();

        foreach (['refund-key-0001', 'refund-key-fresh'] as $key) {
            $response = $this->postJson($this->url($order), $this->body(['idempotency_key' => $key]), $this->headers($other))
                ->assertNotFound();
            $this->assertSame($missing->getContent(), $response->getContent());
        }
        $this->getJson($this->url($order), $this->headers($other))->assertNotFound()->assertExactJson($missing->json());
        $this->getJson('/api/agent/refunds/'.$existing->json('data.id'), $this->headers($other))
            ->assertNotFound()->assertExactJson($missing->json());
        $this->getJson('/api/agent/refunds/999999', $this->headers($other))
            ->assertNotFound()->assertExactJson($missing->json());
        $this->getJson('/api/agent/orders/999999/refunds', $this->headers($other))
            ->assertNotFound()->assertExactJson($missing->json());
        $this->assertDatabaseCount('refunds', 1);
    }

    #[Test]
    public function auto_above_ceiling_is_held_without_gateway_call(): void
    {
        $order = $this->createOrder();
        $this->postJson($this->url($order), $this->body(['amount' => '80.00']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'PENDING_APPROVAL');
        $this->postJson($this->url($order), $this->body(['amount' => '80.00']), $this->headers($order))
            ->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL');
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    #[Test]
    public function split_auto_refunds_are_held_after_ceiling(): void
    {
        $order = $this->createOrder();
        $first = $this->postJson($this->url($order), $this->body(['amount' => '30.00']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED');
        $second = $this->postJson($this->url($order), $this->body(['amount' => '30.00', 'idempotency_key' => 'refund-key-0002']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'PENDING_APPROVAL');
        $this->getJson($this->url($order), $this->headers($order))->assertOk()
            ->assertJsonPath('data.0.id', $second->json('data.id'))->assertJsonPath('data.1.id', $first->json('data.id'));
        $this->assertDatabaseCount('simulated_payment_operations', 1);
    }

    #[Test]
    public function refund_exceeding_total_is_422(): void
    {
        $order = $this->createOrder();
        $this->postJson($this->url($order), $this->body(['amount' => '60.00', 'mode' => 'needs_approval']), $this->headers($order))->assertCreated();
        $this->postJson($this->url($order), $this->body(['amount' => '50.00', 'idempotency_key' => 'refund-key-0002']), $this->headers($order))
            ->assertUnprocessable()->assertJsonPath('error_code', 'REFUND_EXCEEDS_PAID');
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    #[Test]
    public function unpaid_cancelled_or_refunded_orders_are_not_refundable(): void
    {
        foreach (['PENDING', 'PROCESSING', 'FAILED', 'CANCELLED', 'REFUNDED'] as $status) {
            $order = $this->createOrder($status);
            $this->postJson($this->url($order), $this->body(), $this->headers($order))
                ->assertUnprocessable()->assertJsonPath('error_code', 'ORDER_NOT_REFUNDABLE');
        }
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    #[Test]
    public function full_refund_moves_order_to_refunded(): void
    {
        config(['services.refunds.auto_approve_limit' => '100.00']);
        $order = $this->createOrder();
        $first = $this->postJson($this->url($order), $this->body(['amount' => '100.00']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->postJson($this->url($order), $this->body(['amount' => '100.00']), $this->headers($order))
            ->assertOk()->assertExactJson($first->json());
        $this->getJson('/api/agent/orders/'.$order->id, $this->headers($order))
            ->assertOk()->assertJsonPath('data.status', 'REFUNDED')->assertJsonPath('data.refunded_amount', '100.00');
    }

    #[Test]
    public function multiple_succeeded_refunds_move_order_only_at_the_exact_total(): void
    {
        config(['services.refunds.auto_approve_limit' => '100.00']);
        $order = $this->createOrder();
        $this->getJson($this->url($order), $this->headers($order))->assertOk()->assertExactJson(['data' => []]);
        $this->postJson($this->url($order), $this->body(['amount' => '33.33']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertSame(OrderStatus::PAID, $order->fresh()->status);
        $this->postJson($this->url($order), $this->body(['amount' => '66.67', 'idempotency_key' => 'refund-key-0002']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
        $this->getJson('/api/agent/orders/'.$order->id, $this->headers($order))
            ->assertOk()->assertJsonPath('data.refunded_amount', '100.00')->assertJsonPath('data.reserved_amount', '100.00');
    }

    #[Test]
    public function declined_refund_releases_the_reservation(): void
    {
        config(['services.simulated_payment.refund_force_outcome' => 'decline', 'services.refunds.auto_approve_limit' => '100.00']);
        $order = $this->createOrder();
        $this->postJson($this->url($order), $this->body(['amount' => '100.00']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'FAILED')
            ->assertJsonPath('data.failure_message', 'Simulated refund decline.');
        $this->getJson('/api/agent/orders/'.$order->id, $this->headers($order))
            ->assertOk()->assertJsonPath('data.reserved_amount', '0.00');
        config(['services.simulated_payment.refund_force_outcome' => 'success']);
        $this->postJson($this->url($order), $this->body(['amount' => '100.00', 'idempotency_key' => 'refund-key-0002']), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertSame(OrderStatus::REFUNDED, $order->fresh()->status);
    }

    #[Test]
    public function timed_out_refund_stays_pending_reserved_and_replay_completes_it_once(): void
    {
        config(['services.simulated_payment.timeout_after_persist' => true]);
        $order = $this->createOrder();
        $pending = $this->postJson($this->url($order), $this->body(), $this->headers($order))
            ->assertCreated()->assertJsonPath('data.status', 'PENDING');
        $this->getJson('/api/agent/orders/'.$order->id, $this->headers($order))->assertOk()
            ->assertJsonPath('data.reserved_amount', '20.00')->assertJsonPath('data.refunded_amount', '0.00');
        $this->postJson($this->url($order), $this->body(['amount' => '81.00', 'idempotency_key' => 'refund-key-0002']), $this->headers($order))
            ->assertUnprocessable()->assertJsonPath('error_code', 'REFUND_EXCEEDS_PAID');
        config(['services.simulated_payment.timeout_after_persist' => false]);
        $this->postJson($this->url($order), $this->body(), $this->headers($order))->assertOk()
            ->assertJsonPath('data.id', $pending->json('data.id'))->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseHas('simulated_payment_operations', ['request_count' => 2, 'side_effect_count' => 1]);
    }

    #[Test]
    #[DataProvider('malformedAmounts')]
    public function malformed_amounts_are_422(mixed $amount): void
    {
        $order = $this->createOrder();
        $this->postJson($this->url($order), $this->body(['amount' => $amount]), $this->headers($order))
            ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonValidationErrors('amount');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    public static function malformedAmounts(): array
    {
        return [
            'zero' => ['0'], 'decimal zero' => ['0.00'], 'negative' => ['-5'],
            'precision' => ['10.005'], 'exponent' => ['1e3'], 'newline' => ["10\n"],
            'json number' => [10.5], 'integer number' => [10], 'leading space' => [' 10'],
            'trailing space' => ['10 '], 'too many digits' => ['100000000'], 'null' => [null],
            'array' => [['10.00']], 'boolean' => [true],
        ];
    }

    #[Test]
    public function missing_or_wrong_service_token_on_new_routes_is_401(): void
    {
        $order = $this->createOrder();
        foreach ([[], ['X-Service-Token' => 'wrong-token-not-secret']] as $headers) {
            $headers['X-Customer-Id'] = (string) $order->user_id;
            $this->postJson($this->url($order), $this->body(), $headers)->assertUnauthorized();
            $this->getJson($this->url($order), $headers)->assertUnauthorized();
            $this->getJson('/api/agent/refunds/1', $headers)->assertUnauthorized();
        }
        $this->assertDatabaseCount('refunds', 0);
    }

    #[Test]
    public function invalid_required_fields_are_422(): void
    {
        $order = $this->createOrder();
        foreach ([
            ['reason' => ''], ['reason' => str_repeat('r', 501)], ['reason' => 123],
            ['mode' => 'invalid'], ['idempotency_key' => 'short'], ['idempotency_key' => str_repeat('k', 256)],
        ] as $changes) {
            $this->postJson($this->url($order), $this->body($changes), $this->headers($order))
                ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR');
        }
        $this->postJson($this->url($order), [], $this->headers($order))->assertUnprocessable()
            ->assertJsonValidationErrors(['amount', 'reason', 'mode', 'idempotency_key']);
        $this->assertDatabaseCount('refunds', 0);
    }

    #[Test]
    public function execution_calls_gateway_outside_its_transactions_and_returns_final_refund_unchanged(): void
    {
        $order = $this->createOrder();
        $level = DB::transactionLevel();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('refund')->once()->withArgs(function (int $id, string $amount, string $key) use ($order, $level): bool {
            $this->assertSame($level, DB::transactionLevel());
            $this->assertDatabaseHas('refunds', ['order_id' => $id, 'status' => 'PENDING', 'provider_idempotency_key' => $key]);

            return $id === $order->id && $amount === '20.00' && str_starts_with($key, 'refund-');
        })->andReturn(PaymentResult::successful());
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $response = $this->postJson($this->url($order), $this->body(), $this->headers($order))->assertCreated();
        $refund = app(ExecuteRefundUseCase::class)->execute($response->json('data.id'));
        $this->assertSame(RefundStatus::SUCCEEDED, $refund->status);
    }

    #[Test]
    public function every_gateway_throwable_leaves_pending_and_logs_identifiers_without_exception_message(): void
    {
        $order = $this->createOrder();
        Log::spy();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('refund')->once()->andThrow(new \Error('sensitive-provider-detail'));
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $response = $this->postJson($this->url($order), $this->body(), $this->headers($order) + ['X-Trace-Id' => 'throwable-trace'])
            ->assertCreated()->assertJsonPath('data.status', 'PENDING');
        Log::shouldHaveReceived('warning')->once()->with('Refund gateway outcome is unknown.', [
            'trace_id' => 'throwable-trace', 'refund_id' => $response->json('data.id'),
        ]);
        $this->assertDatabaseHas('refunds', ['id' => $response->json('data.id'), 'status' => 'PENDING']);
    }

    #[Test]
    public function refund_transactions_query_the_order_first(): void
    {
        $order = $this->createOrder();
        $firstQueries = [];
        $waitingForFirstQuery = false;
        $dispatcher = DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher(clone $dispatcher);
        DB::connection()->getEventDispatcher()->listen(\Illuminate\Database\Events\TransactionBeginning::class,
            function () use (&$waitingForFirstQuery): void {
                $waitingForFirstQuery = true;
            });
        DB::listen(function ($query) use (&$waitingForFirstQuery, &$firstQueries): void {
            if ($waitingForFirstQuery) {
                $firstQueries[] = $query->sql;
                $waitingForFirstQuery = false;
            }
        });
        try {
            $this->postJson($this->url($order), $this->body(), $this->headers($order))->assertCreated();
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
        }
        $this->assertCount(2, $firstQueries);
        foreach ($firstQueries as $query) {
            $this->assertStringContainsString('from "orders"', $query);
        }
    }

    #[Test]
    public function duplicate_insert_is_reread_after_rollback_and_replayed_or_conflicted(): void
    {
        $order = $this->createOrder();
        $first = $this->postJson($this->url($order), $this->body(['mode' => 'needs_approval']), $this->headers($order))->assertCreated();
        $stored = Refund::query()->findOrFail($first->json('data.id'));
        $level = DB::transactionLevel();
        $repository = Mockery::mock(RefundRepositoryInterface::class);
        $lookups = 0;
        $repository->shouldReceive('findByKey')->with('refund-key-0001')->twice()
            ->andReturnUsing(function () use (&$lookups, $stored, $level): ?Refund {
                $this->assertSame(++$lookups === 1 ? $level + 1 : $level, DB::transactionLevel());

                return $lookups === 1 ? null : $stored;
            });
        $repository->shouldReceive('forOrderLocked')->with($order->id)->once()->andReturn([]);
        $repository->shouldReceive('create')->once()->andThrow(new UniqueConstraintViolationException(
            'sqlite', 'insert into refunds', [], new \RuntimeException('Simulated duplicate key')
        ));
        $this->app->instance(RefundRepositoryInterface::class, $repository);
        $result = app(CreateRefundUseCase::class)->execute(
            $order->id, $order->user_id, '20.00', 'Concurrent request.', 'refund-key-0001', 'needs_approval', null
        );
        $this->assertFalse($result['created']);
        $this->assertSame($stored->id, $result['refund']->id);
        $this->assertSame($level, DB::transactionLevel());

        $repository = Mockery::mock(RefundRepositoryInterface::class);
        $repository->shouldReceive('findByKey')->with('refund-key-0001')->twice()->andReturn(null, $stored);
        $repository->shouldReceive('forOrderLocked')->with($order->id)->once()->andReturn([]);
        $repository->shouldReceive('create')->once()->andThrow(new UniqueConstraintViolationException(
            'sqlite', 'insert into refunds', [], new \RuntimeException('Simulated duplicate key')
        ));
        $this->app->instance(RefundRepositoryInterface::class, $repository);
        try {
            app(CreateRefundUseCase::class)->execute(
                $order->id, $order->user_id, '21.00', 'Concurrent request.', 'refund-key-0001', 'needs_approval', null
            );
            $this->fail('A different fingerprint must conflict after a duplicate insert.');
        } catch (IdempotencyConflictException $e) {
            $this->assertStringContainsString('refund-key-0001', $e->getMessage());
        }
        $this->assertSame($level, DB::transactionLevel());
        $this->assertDatabaseCount('refunds', 1);
    }

    #[Test]
    public function conditional_order_update_only_changes_paid_orders(): void
    {
        $repository = app(OrderRepositoryInterface::class);
        $paid = $this->createOrder();
        $cancelled = $this->createOrder('CANCELLED');
        $this->assertTrue($repository->markRefundedIfPaid($paid->id));
        $this->assertFalse($repository->markRefundedIfPaid($paid->id));
        $this->assertFalse($repository->markRefundedIfPaid($cancelled->id));
        $this->assertFalse($repository->markRefundedIfPaid(999999));
        $this->assertSame(OrderStatus::CANCELLED, $cancelled->fresh()->status);
    }

    private function createOrder(string $status = 'PAID'): Order
    {
        return Order::query()->create([
            'user_id' => User::factory()->create()->id,
            'status' => $status,
            'total_amount' => '100.00',
            'idempotency_key' => 'order-'.fake()->uuid(),
        ]);
    }

    private function body(array $changes = []): array
    {
        return $changes + [
            'amount' => '20.00', 'reason' => 'Customer requested a partial refund.',
            'mode' => 'auto', 'idempotency_key' => 'refund-key-0001',
        ];
    }

    private function headers(Order $order): array
    {
        return ['X-Service-Token' => 'test-token-not-secret', 'X-Customer-Id' => (string) $order->user_id];
    }

    private function url(Order $order): string
    {
        return '/api/agent/orders/'.$order->id.'/refunds';
    }
}
