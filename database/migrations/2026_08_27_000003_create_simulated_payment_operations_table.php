<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('simulated_payment_operations', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('status', 20);
            $table->string('message');
            $table->unsignedInteger('request_count')->default(0);
            $table->unsignedInteger('side_effect_count')->default(1);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('simulated_payment_operations');
    }
};
