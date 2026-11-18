<?php

use App\Infrastructure\Persistence\LegacyProcessingBackfill;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(LegacyProcessingBackfill::class)->run();
    }

    public function down(): void
    {
        // Classification is removed with the metadata column migration.
    }
};
