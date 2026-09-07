<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('max_daily_doses', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('generic_id')->constrained('generics')->cascadeOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->string('population', 10)->default('adult');
            $table->decimal('max_mg_per_day', 12, 3)->nullable();
            $table->decimal('max_mg_per_kg_per_day', 8, 3)->nullable();
            $table->decimal('max_mg_per_dose', 12, 3)->nullable();
            $table->smallInteger('min_age_months')->nullable();
            $table->smallInteger('max_age_months')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['generic_id'], 'max_daily_doses_generic_id_idx');
            $table->index(['route_id'], 'max_daily_doses_route_id_idx');
            $table->index(['catalog_version_id'], 'max_daily_doses_catalog_version_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX max_daily_doses_generic_route_population_min_age_uniq ON max_daily_doses (generic_id, COALESCE(route_id, 0), population, COALESCE(min_age_months, -1))');
        DB::statement("ALTER TABLE max_daily_doses ADD CONSTRAINT max_daily_doses_population_check CHECK (population IN ('adult', 'pediatric', 'elderly'))");
        DB::statement('ALTER TABLE max_daily_doses ADD CONSTRAINT max_daily_doses_any_max_check CHECK (max_mg_per_day IS NOT NULL OR max_mg_per_kg_per_day IS NOT NULL OR max_mg_per_dose IS NOT NULL)');
    }

    public function down(): void
    {
        Schema::dropIfExists('max_daily_doses');
    }
};
