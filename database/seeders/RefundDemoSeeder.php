<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Order\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RefundDemoSeeder extends Seeder
{
    public function run(): void
    {
        $order = DB::transaction(function (): Order {
            if (User::query()->find(1) === null) {
                User::factory()->create([
                    'id' => 1,
                    'name' => 'Refund Demo Customer',
                    'email' => 'refund-demo@example.com',
                ]);
            }

            $product = Product::query()->create([
                'name' => 'Refund demo item',
                'price' => '100.00',
                'stock' => 0,
            ]);
            $order = Order::query()->create([
                'user_id' => 1,
                'status' => OrderStatus::PAID,
                'total_amount' => '100.00',
                'idempotency_key' => 'refund-demo-'.Str::uuid(),
            ]);
            $order->items()->create([
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => '100.00',
            ]);

            return $order;
        });

        $this->command?->info("Refund demo order ID: {$order->id}");
    }
}
