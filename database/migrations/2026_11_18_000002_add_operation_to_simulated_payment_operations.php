<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('simulated_payment_operations', function (Blueprint $table) {
            $table->string('operation', 20)->default('charge');
        });
    }

    public function down(): void
    {
        Schema::table('simulated_payment_operations', function (Blueprint $table) {
            $table->dropColumn('operation');
        });
    }
};
