<?php

declare(strict_types=1);

namespace Tests\Feature\Agent;

use App\Domain\Payment\Contracts\PaymentGatewayInterface;
use App\Domain\Payment\ValueObjects\PaymentResult;
use App\Domain\Refund\RefundStatus;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class DecideRefundTest extends TestCase
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
    public function approve_executes_once_and_records_decider_without_rechecking_reserved_cap(): void
    {
        $refund = $this->heldRefund(amount: '100.00');
        $this->travelTo(now()->startOfSecond());

        $this->postJson($this->url($refund, 'approve'), ['decided_by' => 'ops-reviewer'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'SUCCEEDED')->assertJsonPath('data.decided_by', 'ops-reviewer')
            ->assertJsonPath('data.customer_id', $refund->customer_id);
        $this->assertTrue($refund->fresh()->decided_at->equalTo(now()));
        $this->postJson($this->url($refund, 'approve'), ['decided_by' => 'another-reviewer'], $this->headers())
            ->assertConflict()->assertJsonPath('error_code', 'REFUND_NOT_PENDING_APPROVAL');
        $this->assertSame('ops-reviewer', $refund->fresh()->decided_by);
        $this->assertDatabaseCount('simulated_payment_operations', 1);
        $this->assertDatabaseHas('simulated_payment_operations', ['request_count' => 1, 'side_effect_count' => 1]);
    }

    #[Test]
    #[DataProvider('nonApprovalStatuses')]
    public function decisions_require_pending_approval(string $status): void
    {
        $refund = $this->heldRefund();
        $refund->update(['status' => $status]);
        foreach (['approve', 'reject'] as $action) {
            $this->postJson($this->url($refund, $action), ['decided_by' => 'ops', 'reason' => 'Declined.'], $this->headers())
                ->assertConflict()->assertJsonPath('error_code', 'REFUND_NOT_PENDING_APPROVAL')->assertJsonMissingPath('data');
        }
        $this->assertSame($status, $refund->fresh()->status->value);
        $this->assertNull($refund->fresh()->decided_at);
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    public static function nonApprovalStatuses(): array
    {
        return [['PENDING'], ['SUCCEEDED'], ['FAILED'], ['REJECTED']];
    }

    #[Test]
    public function reject_releases_reservation_records_reason_and_is_final(): void
    {
        $order = $this->createOrder();
        $refund = $this->heldRefund($order, '80.00');
        $this->createRefund($order, '30.00')->assertUnprocessable()->assertJsonPath('error_code', 'REFUND_EXCEEDS_PAID');
        $this->travelTo(now()->startOfSecond());
        $this->postJson($this->url($refund, 'reject'), ['decided_by' => 'ops', 'reason' => 'Request is not eligible.'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.customer_id', $refund->customer_id)
            ->assertJsonPath('data.decided_by', 'ops')->assertJsonPath('data.decision_reason', 'Request is not eligible.');
        $this->assertTrue($refund->fresh()->decided_at->equalTo(now()));
        $this->postJson($this->url($refund, 'approve'), ['decided_by' => 'ops'], $this->headers())
            ->assertConflict()->assertJsonPath('error_code', 'REFUND_NOT_PENDING_APPROVAL');
        $this->createRefund($order, '30.00', 'auto')->assertCreated()->assertJsonPath('data.status', 'SUCCEEDED');
        $this->assertDatabaseCount('simulated_payment_operations', 1);
    }

    #[Test]
    public function declined_approval_releases_the_reservation(): void
    {
        $order = $this->createOrder();
        $refund = $this->heldRefund($order, '100.00');
        config(['services.simulated_payment.refund_force_outcome' => 'decline']);
        $this->postJson($this->url($refund, 'approve'), ['decided_by' => 'ops'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'FAILED');
        config(['services.simulated_payment.refund_force_outcome' => 'success']);
        $this->createRefund($order, '100.00')->assertCreated()->assertJsonPath('data.status', 'PENDING_APPROVAL');
    }

    #[Test]
    public function reconcile_completes_timed_out_approval_exactly_once(): void
    {
        $refund = $this->heldRefund();
        config(['services.simulated_payment.timeout_after_persist' => true]);
        $this->postJson($this->url($refund, 'approve'), ['decided_by' => 'ops'], $this->headers())
            ->assertOk()->assertJsonPath('data.status', 'PENDING');
        $this->assertDatabaseHas('simulated_payment_operations', ['request_count' => 1, 'side_effect_count' => 1]);
        config(['services.simulated_payment.timeout_after_persist' => false]);
        $this->artisan('refunds:reconcile', ['refundId' => $refund->id])->expectsOutput('SUCCEEDED')->assertSuccessful();
        $this->artisan('refunds:reconcile', ['refundId' => $refund->id])->expectsOutput('SUCCEEDED')->assertSuccessful();
        $this->assertSame(RefundStatus::SUCCEEDED, $refund->fresh()->status);
        $this->assertDatabaseHas('simulated_payment_operations', ['request_count' => 2, 'side_effect_count' => 1]);
    }

    #[Test]
    public function reconcile_all_pending_replays_timed_out_refunds_once_and_skips_other_statuses(): void
    {
        $first = $this->heldRefund();
        $second = $this->heldRefund();
        $held = $this->heldRefund();
        $rejected = $this->heldRefund();
        $rejected->update(['status' => 'REJECTED']);
        config(['services.simulated_payment.timeout_after_persist' => true]);
        foreach ([$first, $second] as $refund) {
            $this->postJson($this->url($refund, 'approve'), ['decided_by' => 'ops'], $this->headers())
                ->assertOk()->assertJsonPath('data.status', 'PENDING');
        }
        $second->forceFill(['created_at' => $first->created_at->subDay()])->save();
        config(['services.simulated_payment.timeout_after_persist' => false]);

        $this->artisan('refunds:reconcile', ['--all-pending' => true])
            ->expectsOutput("{$second->id}: SUCCEEDED")
            ->expectsOutput("{$first->id}: SUCCEEDED")->assertSuccessful();
        $this->artisan('refunds:reconcile', ['--all-pending' => true])->doesntExpectOutput()->assertSuccessful();
        foreach ([$first, $second] as $refund) {
            $this->assertSame(RefundStatus::SUCCEEDED, $refund->fresh()->status);
            $this->assertDatabaseHas('simulated_payment_operations', [
                'idempotency_key' => $refund->provider_idempotency_key, 'request_count' => 2, 'side_effect_count' => 1,
            ]);
        }
        $this->assertSame(RefundStatus::PENDING_APPROVAL, $held->fresh()->status);
        $this->assertSame(RefundStatus::REJECTED, $rejected->fresh()->status);
        $this->assertDatabaseCount('simulated_payment_operations', 2);
    }

    #[Test]
    public function reconcile_all_pending_limits_to_the_oldest_hundred_with_id_tiebreaker(): void
    {
        $refund = $this->heldRefund(amount: '0.01');
        $refund->forceFill(['status' => 'PENDING', 'created_at' => '2026-01-02 00:00:00'])->save();
        $refunds = [$refund];
        for ($index = 1; $index <= 100; $index++) {
            $copy = $refund->replicate();
            $copy->forceFill([
                'idempotency_key' => 'batch-key-'.$index,
                'provider_idempotency_key' => 'batch-provider-'.$index,
                'created_at' => $index === 100 ? '2026-01-01 00:00:00' : '2026-01-02 00:00:00',
            ])->save();
            $refunds[] = $copy;
        }
        $expected = [$refunds[100], ...array_slice($refunds, 0, 99)];
        $keys = [];
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('refund')->times(100)->andReturnUsing(function (int $orderId, string $amount, string $key) use (&$keys): PaymentResult {
            $keys[] = $key;

            return PaymentResult::successful();
        });
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $command = $this->artisan('refunds:reconcile', ['--all-pending' => true]);
        foreach ($expected as $row) {
            $command->expectsOutput("{$row->id}: SUCCEEDED");
        }
        $command->assertSuccessful()->run();

        $this->assertSame(array_map(fn (Refund $row): string => $row->provider_idempotency_key, $expected), $keys);
        $this->assertSame(100, Refund::query()->where('status', 'SUCCEEDED')->count());
        $this->assertSame(RefundStatus::PENDING, $refunds[99]->fresh()->status);
    }

    #[Test]
    public function reconcile_requires_one_target_form(): void
    {
        $refund = $this->heldRefund();
        $this->artisan('refunds:reconcile')->expectsOutput('Provide a refund ID or --all-pending.')->assertFailed();
        $this->artisan('refunds:reconcile', ['refundId' => $refund->id, '--all-pending' => true])
            ->expectsOutput('Choose a refund ID or --all-pending, not both.')->assertFailed();
        $this->assertSame(RefundStatus::PENDING_APPROVAL, $refund->fresh()->status);
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    #[Test]
    public function reconcile_does_not_touch_held_or_final_refunds_and_missing_refund_fails(): void
    {
        $refund = $this->heldRefund();
        foreach (['PENDING_APPROVAL', 'SUCCEEDED', 'FAILED', 'REJECTED'] as $status) {
            $refund->update(['status' => $status]);
            $before = $refund->fresh()->getAttributes();
            $this->artisan('refunds:reconcile', ['refundId' => $refund->id])->expectsOutput($status)->assertSuccessful();
            $this->assertSame($before, $refund->fresh()->getAttributes());
        }
        $this->artisan('refunds:reconcile', ['refundId' => 999999])->assertFailed();
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    #[Test]
    #[DataProvider('queueStatuses')]
    public function queue_lists_only_the_requested_status_oldest_first_with_limit_fifty(string $status): void
    {
        $refund = $this->heldRefund();
        $refund->forceFill(['status' => $status, 'created_at' => '2026-01-01 00:00:00'])->save();
        $ids = [$refund->id];
        for ($index = 1; $index <= 51; $index++) {
            $copy = $refund->replicate();
            $copy->forceFill([
                'idempotency_key' => 'queue-key-'.$index,
                'provider_idempotency_key' => 'queue-provider-'.$index,
                'created_at' => '2026-01-02 00:00:00',
            ])->save();
            $ids[] = $copy->id;
        }
        $excluded = $this->heldRefund();
        foreach (RefundStatus::cases() as $excludedStatus) {
            if ($excludedStatus->value === $status) {
                continue;
            }
            $excluded->update(['status' => $excludedStatus]);
            $response = $this->getJson('/api/agent/refunds?status='.$status, $this->headers())
                ->assertOk()->assertJsonCount(50, 'data')->assertJsonPath('data.0.customer_id', $refund->customer_id);
            $this->assertSame(array_slice($ids, 0, 50), array_column($response->json('data'), 'id'));
        }
    }

    public static function queueStatuses(): array
    {
        return [['PENDING_APPROVAL'], ['PENDING']];
    }

    #[Test]
    public function queue_requires_an_exact_supported_pending_status(): void
    {
        foreach (['', '?status=SUCCEEDED', '?status=pending_approval', '?status[]=PENDING_APPROVAL', '?status=%20PENDING_APPROVAL', '?status=PENDING_APPROVAL%0A', '?status=PENDING%20', '?status=%20PENDING', '?status=PENDING%0A', '?status[]=PENDING'] as $query) {
            $this->getJson('/api/agent/refunds'.$query, $this->headers())->assertUnprocessable()
                ->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonValidationErrors('status');
        }
        $this->getJson('/api/agent/refunds?status=PENDING_APPROVAL', $this->headers())->assertOk()->assertExactJson(['data' => []]);
        $this->getJson('/api/agent/refunds?status=PENDING', $this->headers())->assertOk()->assertExactJson(['data' => []]);
    }

    #[Test]
    public function customer_refunds_filter_by_customer_and_inclusive_since_newest_first(): void
    {
        $order = $this->createOrder();
        $old = $this->heldRefund($order);
        $boundary = $this->heldRefund($order);
        $newest = $this->heldRefund($order);
        $other = $this->heldRefund();
        $old->forceFill(['created_at' => '2026-01-01 11:59:59'])->save();
        $boundary->forceFill(['created_at' => '2026-01-01 12:00:00', 'status' => 'REJECTED'])->save();
        $newest->forceFill(['created_at' => '2026-01-02 12:00:00', 'status' => 'SUCCEEDED'])->save();
        $other->forceFill(['created_at' => '2026-01-03 12:00:00'])->save();
        $response = $this->getJson('/api/agent/customers/'.$order->user_id.'/refunds?since=2026-01-01T14:00:00%2B02:00', $this->headers())
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('data.0.customer_id', $order->user_id);
        $this->assertSame([$newest->id, $boundary->id], array_column($response->json('data'), 'id'));
        $this->getJson('/api/agent/customers/999999/refunds?since=2026-01-01T12:00:00Z', $this->headers())
            ->assertOk()->assertExactJson(['data' => []]);
    }

    #[Test]
    public function customer_history_rejects_invalid_customer_ids_and_timestamps(): void
    {
        foreach (['0', '01', '-1', 'abc', '1%20', '1%0A'] as $id) {
            $this->getJson('/api/agent/customers/'.$id.'/refunds?since=2026-01-01T00:00:00Z', $this->headers())
                ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonValidationErrors('customerId');
        }
        foreach (['', '?since=', '?since=not-a-date', '?since=2026-02-30T00:00:00Z', '?since=2026-01-01', '?since=tomorrow', '?since[]=2026-01-01T00:00:00Z'] as $query) {
            $this->getJson('/api/agent/customers/1/refunds'.$query, $this->headers())
                ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonValidationErrors('since');
        }
    }

    #[Test]
    public function decision_fields_are_validated_before_any_mutation(): void
    {
        $refund = $this->heldRefund();
        foreach (['approve', 'reject'] as $action) {
            foreach ([[], ['decided_by' => ''], ['decided_by' => 123], ['decided_by' => str_repeat('x', 101)]] as $body) {
                $this->postJson($this->url($refund, $action), $body + ['reason' => 'Reason.'], $this->headers())
                    ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonValidationErrors('decided_by');
            }
        }
        foreach ([[], ['reason' => ''], ['reason' => 123], ['reason' => str_repeat('x', 501)]] as $body) {
            $this->postJson($this->url($refund, 'reject'), $body + ['decided_by' => 'ops'], $this->headers())
                ->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonValidationErrors('reason');
        }
        $this->assertSame(RefundStatus::PENDING_APPROVAL, $refund->fresh()->status);
        $this->assertDatabaseCount('simulated_payment_operations', 0);
    }

    #[Test]
    public function all_new_routes_require_a_valid_service_token(): void
    {
        foreach ([[], ['X-Service-Token' => 'wrong-token-not-secret']] as $headers) {
            foreach (['approve', 'reject'] as $action) {
                $this->postJson('/api/agent/refunds/1/'.$action, ['decided_by' => 'ops', 'reason' => 'Reason.'], $headers)
                    ->assertUnauthorized()->assertJsonPath('error_code', 'UNAUTHORIZED');
            }
            $this->getJson('/api/agent/refunds?status=PENDING_APPROVAL', $headers)->assertUnauthorized();
            $this->getJson('/api/agent/customers/1/refunds?since=2026-01-01T00:00:00Z', $headers)->assertUnauthorized();
        }
    }

    #[Test]
    public function missing_refunds_return_not_found_for_both_decisions(): void
    {
        foreach (['approve', 'reject'] as $action) {
            $this->postJson('/api/agent/refunds/999999/'.$action, ['decided_by' => 'ops', 'reason' => 'Reason.'], $this->headers())
                ->assertNotFound()->assertJsonPath('error_code', 'NOT_FOUND');
        }
    }

    #[Test]
    public function decisions_lock_order_first_and_gateway_runs_after_decision_transaction(): void
    {
        $approve = $this->heldRefund();
        $reject = $this->heldRefund();
        $level = DB::transactionLevel();
        $gateway = Mockery::mock(PaymentGatewayInterface::class);
        $gateway->shouldReceive('refund')->once()->andReturnUsing(function () use ($approve, $level): PaymentResult {
            $this->assertSame($level, DB::transactionLevel());
            $this->assertDatabaseHas('refunds', ['id' => $approve->id, 'status' => 'PENDING', 'decided_by' => 'ops']);

            return PaymentResult::successful();
        });
        $this->app->instance(PaymentGatewayInterface::class, $gateway);
        $firstQueries = [];
        $waiting = false;
        $dispatcher = DB::connection()->getEventDispatcher();
        DB::connection()->setEventDispatcher(clone $dispatcher);
        DB::connection()->getEventDispatcher()->listen(\Illuminate\Database\Events\TransactionBeginning::class,
            function () use (&$waiting): void {
                $waiting = true;
            });
        DB::listen(function ($query) use (&$waiting, &$firstQueries): void {
            if ($waiting) {
                $firstQueries[] = $query->sql;
                $waiting = false;
            }
        });
        try {
            $this->postJson($this->url($approve, 'approve'), ['decided_by' => 'ops'], $this->headers())->assertOk();
            $this->postJson($this->url($reject, 'reject'), ['decided_by' => 'ops', 'reason' => 'Reason.'], $this->headers())->assertOk();
        } finally {
            DB::connection()->setEventDispatcher($dispatcher);
        }
        $this->assertCount(3, $firstQueries);
        foreach ($firstQueries as $query) {
            $this->assertStringContainsString('from "orders"', $query);
        }
    }

    private function createOrder(): Order
    {
        return Order::query()->create([
            'user_id' => User::factory()->create()->id, 'status' => 'PAID',
            'total_amount' => '100.00', 'idempotency_key' => 'order-'.fake()->uuid(),
        ]);
    }

    private function heldRefund(?Order $order = null, string $amount = '20.00'): Refund
    {
        $response = $this->createRefund($order ?? $this->createOrder(), $amount)->assertCreated()
            ->assertJsonPath('data.status', 'PENDING_APPROVAL');

        return Refund::query()->findOrFail($response->json('data.id'));
    }

    private function createRefund(Order $order, string $amount, string $mode = 'needs_approval'): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/agent/orders/'.$order->id.'/refunds', [
            'amount' => $amount, 'mode' => $mode, 'reason' => 'Customer requested a refund.',
            'idempotency_key' => 'refund-'.fake()->uuid(),
        ], $this->headers() + ['X-Customer-Id' => (string) $order->user_id]);
    }

    private function headers(): array
    {
        return ['X-Service-Token' => 'test-token-not-secret'];
    }

    private function url(Refund $refund, string $action): string
    {
        return '/api/agent/refunds/'.$refund->id.'/'.$action;
    }
}
