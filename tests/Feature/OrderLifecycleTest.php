<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Infrastructure\Locking\InMemoryDistributedLock;
use App\Infrastructure\Queue\Jobs\ProcessOrderJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OrderLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        InMemoryDistributedLock::reset();
    }

    // ─── Create Order ─────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_creates_an_order_successfully(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'test-key-001',
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
                ['product_id' => 2, 'quantity' => 2],
            ],
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'user_id',
                    'status',
                    'total_amount',
                    'idempotency_key',
                    'items',
                    'created_at',
                ],
            ])
            ->assertJsonPath('data.status', 'PENDING')
            ->assertJsonPath('data.user_id', 1);

        Queue::assertPushed(ProcessOrderJob::class);

        // Verify stock was decremented
        $this->assertDatabaseHas('products', [
            'id' => 1,
            'stock' => 49, // was 50, ordered 1
        ]);
        $this->assertDatabaseHas('products', [
            'id' => 2,
            'stock' => 198, // was 200, ordered 2
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_order_with_insufficient_stock(): void
    {
        Queue::fake();

        $response = $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'test-key-002',
            'items' => [
                ['product_id' => 5, 'quantity' => 10], // Headphones only has 1 stock
            ],
        ]);

        $response->assertStatus(409)
            ->assertJsonPath('error', 'insufficient_stock');

        Queue::assertNothingPushed();
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_idempotent_response_for_duplicate_key(): void
    {
        Queue::fake();

        // First request
        $response1 = $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'duplicate-key',
            'items' => [
                ['product_id' => 2, 'quantity' => 1],
            ],
        ]);

        $response1->assertStatus(201);
        $response1->assertHeader('Idempotency-Replayed', 'false');
        $orderId = $response1->json('data.id');

        // Second request with same key — should return existing order
        $response2 = $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'duplicate-key',
            'items' => [
                ['product_id' => 2, 'quantity' => 1],
            ],
        ]);

        $response2->assertStatus(200)
            ->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('meta.idempotency_replayed', true);

        // Stock should only be decremented once
        $this->assertDatabaseHas('products', [
            'id' => 2,
            'stock' => 199, // was 200, decremented only once
        ]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_rejects_reuse_of_an_idempotency_key_with_different_parameters(): void
    {
        Queue::fake();

        $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'conflicting-key',
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ])->assertStatus(201);

        $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'conflicting-key',
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
        ])->assertStatus(409)
            ->assertJsonPath('error_code', 'IDEMPOTENCY_CONFLICT');

        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 49]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_validates_request_payload(): void
    {
        $response = $this->postJson('/api/orders', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_id', 'idempotency_key', 'items']);
    }

    // ─── Show Order ───────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_shows_an_existing_order(): void
    {
        Queue::fake();

        $createResponse = $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'show-test',
            'items' => [
                ['product_id' => 1, 'quantity' => 1],
            ],
        ]);

        $createResponse->assertStatus(201);
        $orderId = $createResponse->json('data.id');

        $response = $this->getJson("/api/orders/{$orderId}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $orderId)
            ->assertJsonPath('data.status', 'PENDING');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_returns_404_for_nonexistent_order(): void
    {
        $response = $this->getJson('/api/orders/99999');

        $response->assertStatus(404);
    }

    // ─── List Orders ──────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_lists_orders_with_pagination(): void
    {
        Queue::fake();

        // Create 3 orders
        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/orders', [
                'user_id' => 1,
                'idempotency_key' => "list-test-{$i}",
                'items' => [
                    ['product_id' => 2, 'quantity' => 1],
                ],
            ])->assertStatus(201);
        }

        $response = $this->getJson('/api/orders?user_id=1');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data',
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ]);

        $this->assertSame(3, $response->json('meta.total'));
    }

    // ─── Cancel Order ─────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_cancels_a_pending_order(): void
    {
        Queue::fake();

        $createResponse = $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'cancel-test',
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
        ]);

        $createResponse->assertStatus(201);
        $orderId = $createResponse->json('data.id');

        // Stock after create: 50 - 2 = 48
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 48]);

        $response = $this->postJson("/api/orders/{$orderId}/cancel");

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');

        // Stock should be restored: 48 + 2 = 50
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 50]);
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function cancelling_already_cancelled_order_is_idempotent(): void
    {
        Queue::fake();

        $createResponse = $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'cancel-idem-test',
            'items' => [
                ['product_id' => 2, 'quantity' => 1],
            ],
        ]);

        $createResponse->assertStatus(201);
        $orderId = $createResponse->json('data.id');

        // Cancel first time
        $this->postJson("/api/orders/{$orderId}/cancel")->assertStatus(200);

        // Cancel second time — should still succeed (idempotent)
        $response = $this->postJson("/api/orders/{$orderId}/cancel");
        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'CANCELLED');
    }

    // ─── Products ─────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function it_lists_products(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['id', 'name', 'price', 'stock'],
                ],
            ]);

        $this->assertCount(5, $response->json('data'));
    }

    // ─── Trace ID ─────────────────────────────────────

    #[\PHPUnit\Framework\Attributes\Test]
    public function response_includes_trace_id_header(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertHeader('X-Trace-Id');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function provided_trace_id_is_propagated(): void
    {
        $traceId = 'custom-trace-abc-123';

        $response = $this->getJson('/api/products', [
            'X-Trace-Id' => $traceId,
        ]);

        $response->assertHeader('X-Trace-Id', $traceId);
    }
}
