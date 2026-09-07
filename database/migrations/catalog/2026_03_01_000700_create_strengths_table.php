<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('strengths', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('brand_id')->constrained('brands')->restrictOnDelete();
            $table->foreignId('generic_id')->constrained('generics')->restrictOnDelete();
            $table->foreignId('dosage_form_id')->constrained('dosage_forms')->restrictOnDelete();
            $table->foreignId('route_id')->nullable()->constrained('routes')->nullOnDelete();
            $table->string('strength_label', 64);
            $table->decimal('strength_value', 12, 4)->nullable();
            $table->string('strength_unit', 16)->nullable();
            $table->decimal('per_volume_ml', 8, 2)->nullable();
            $table->string('pack_size', 48)->nullable();
            $table->bigInteger('unit_price_paisa')->nullable();
            $table->decimal('strength_mg', 12, 4)->nullable();
            $table->decimal('per_ml', 12, 4)->nullable();
            $table->decimal('pack_size_value', 10, 2)->nullable();
            $table->string('pack_unit', 16)->nullable();
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['brand_id', 'dosage_form_id', 'strength_label'], 'strengths_brand_id_dosage_form_id_strength_label_uniq');
            $table->index(['generic_id'], 'strengths_generic_id_idx');
            $table->index(['brand_id'], 'strengths_brand_id_idx');
            $table->index(['dosage_form_id'], 'strengths_dosage_form_id_idx');
            $table->index(['route_id'], 'strengths_route_id_idx');
            $table->index(['catalog_version_id'], 'strengths_catalog_version_id_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('strengths');
    }
};
