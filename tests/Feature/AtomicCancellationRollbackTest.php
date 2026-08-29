<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Application\EventHandlers\OrderCancelledHandler;
use App\Application\UseCases\CancelOrder\CancelOrderUseCase;
use App\Domain\Inventory\Contracts\ProductRepositoryInterface;
use App\Domain\Inventory\Services\InventoryService;
use App\Domain\Order\Contracts\OrderRepositoryInterface;
use App\Domain\Order\ValueObjects\OrderItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class AtomicCancellationRollbackTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function restoration_failure_rolls_back_both_status_and_inventory(): void
    {
        $this->seed();
        Queue::fake();
        $orderId = (int) $this->postJson('/api/orders', [
            'user_id' => 1,
            'idempotency_key' => 'cancel-rollback',
            'items' => [
                ['product_id' => 1, 'quantity' => 2],
            ],
        ])->assertCreated()->json('data.id');

        $products = app(ProductRepositoryInterface::class);
        $failingInventory = new class($products) extends InventoryService
        {
            public function restoreStock(array $items): void
            {
                /** @var OrderItem $first */
                $first = $items[0];
                parent::restoreStock([$first]);

                throw new \RuntimeException('Injected failure after the first restoration.');
            }
        };
        $handler = $this->mock(OrderCancelledHandler::class);
        $handler->shouldNotReceive('handle');
        $useCase = new CancelOrderUseCase(
            app(OrderRepositoryInterface::class),
            $failingInventory,
            $handler
        );

        try {
            $useCase->execute($orderId);
            $this->fail('Expected the injected restoration failure.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('Injected failure', $exception->getMessage());
        }

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'status' => 'PENDING',
            'cancelled_at' => null,
        ]);
        $this->assertDatabaseHas('products', ['id' => 1, 'stock' => 48]);
    }
}
