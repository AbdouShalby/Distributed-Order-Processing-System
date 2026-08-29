<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->char('request_fingerprint', 64)->nullable()->after('idempotency_key');
            $table->uuid('processing_token')->nullable()->after('request_fingerprint');
            $table->timestamp('processing_claimed_at')->nullable()->after('processing_token');
            $table->timestamp('processing_lease_until')->nullable()->after('processing_claimed_at');
            $table->unsignedInteger('processing_attempt')->default(0)->after('processing_lease_until');
            $table->string('payment_idempotency_key')->nullable()->after('processing_attempt');
            $table->string('processing_recovery_state', 32)
                ->nullable()
                ->after('payment_idempotency_key');

            $table->index(
                ['status', 'processing_recovery_state', 'processing_lease_until'],
                'idx_orders_recoverable_processing'
            );
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('idx_orders_recoverable_processing');
            $table->dropColumn([
                'request_fingerprint',
                'processing_token',
                'processing_claimed_at',
                'processing_lease_until',
                'processing_attempt',
                'payment_idempotency_key',
                'processing_recovery_state',
            ]);
        });
    }
};
