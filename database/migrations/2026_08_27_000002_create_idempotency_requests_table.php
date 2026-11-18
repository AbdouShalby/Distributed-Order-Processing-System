<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('idempotency_requests', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 255)->unique();
            $table->char('request_fingerprint', 64);
            $table->string('status', 20);
            $table->foreignId('order_id')
                ->nullable()
                ->unique()
                ->constrained('orders')
                ->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('idempotency_requests');
    }
};
