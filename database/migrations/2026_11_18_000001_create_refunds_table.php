<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('customer_id');
            $table->decimal('amount', 10, 2);
            $table->string('reason', 500);
            $table->string('mode', 20);
            $table->string('status', 20);
            $table->string('idempotency_key', 255)->unique();
            $table->char('request_fingerprint', 64);
            $table->string('provider_idempotency_key', 255)->unique();
            $table->string('trace_id', 64)->nullable();
            $table->string('decided_by', 100)->nullable();
            $table->string('decision_reason', 500)->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->string('failure_message', 255)->nullable();
            $table->timestamps();
            $table->index(['order_id', 'status']);
            $table->index(['customer_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refunds');
    }
};
