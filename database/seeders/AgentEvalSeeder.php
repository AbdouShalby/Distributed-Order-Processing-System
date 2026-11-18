<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Application\Refunds\RefundFingerprint;
use App\Domain\Refund\RefundAmounts;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class AgentEvalSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $seed = json_decode(file_get_contents(database_path('seeders/data/agent-eval-seed.json')), true, flags: JSON_THROW_ON_ERROR);
            $now = now();
            $emails = [1 => 'customer-a@example.test', 2 => 'customer-b@example.test', 3 => 'customer-c@example.test'];

            foreach ($seed['customers'] as $customer) {
                if (User::query()->find($customer['id']) === null) {
                    User::factory()->create([
                        'id' => $customer['id'],
                        'name' => $customer['name'],
                        'email' => $emails[$customer['id']],
                    ]);
                }
            }

            foreach ($seed['orders'] as $order) {
                $timestamp = $now->copy()->subDays($order['created_days_ago']);

                foreach ($order['items'] as $item) {
                    if (! DB::table('products')->where('id', $item['product_id'])->exists()) {
                        DB::table('products')->insert([
                            'id' => $item['product_id'],
                            'name' => 'Eval product '.$item['product_id'],
                            'price' => $item['unit_price'],
                            'stock' => 0,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ]);
                    }
                }

                DB::table('orders')->insert([
                    'id' => $order['id'],
                    'user_id' => $order['user_id'],
                    'status' => $order['status'],
                    'total_amount' => $order['total_amount'],
                    'idempotency_key' => 'agent-eval-order-'.$order['id'],
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp,
                ]);

                foreach ($order['items'] as $item) {
                    DB::table('order_items')->insert($item + [
                        'order_id' => $order['id'],
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ]);
                }

                foreach ($order['refunds'] ?? [] as $index => $refund) {
                    $refundTimestamp = $now->copy()->subDays($refund['created_days_ago']);
                    $suffix = $order['id'].'-'.($index + 1);
                    $amount = RefundAmounts::normalise($refund['amount']);

                    DB::table('refunds')->insert([
                        'order_id' => $order['id'],
                        'customer_id' => $order['user_id'],
                        'amount' => $amount,
                        'status' => $refund['status'],
                        'mode' => $refund['mode'],
                        'reason' => $refund['reason'],
                        'decided_by' => $refund['decided_by'],
                        'idempotency_key' => 'agent-eval-refund-'.$suffix,
                        'provider_idempotency_key' => 'agent-eval-provider-'.$suffix,
                        'request_fingerprint' => RefundFingerprint::of($order['id'], $order['user_id'], $amount, $refund['mode']),
                        'trace_id' => 't-0000000000000000',
                        'created_at' => $refundTimestamp,
                        'updated_at' => $refundTimestamp,
                    ]);
                }
            }
        });
    }
}
