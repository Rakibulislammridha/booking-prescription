<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `prescription_template_items` — prescription_items minus prescription_id / info_url_slug /
// safety_overrides; not immutable. dose_json.normalized is the re-parseable shorthand (PRESCRIPTION.md §3.6).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_template_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_template_id')->constrained('prescription_templates')->cascadeOnDelete();
            $table->smallInteger('sort_order');
            $table->bigInteger('generic_id')->nullable();
            $table->bigInteger('brand_id')->nullable();
            $table->bigInteger('strength_id')->nullable();
            $table->bigInteger('custom_brand_id')->nullable();
            $table->string('generic_name', 160);
            $table->string('brand_name', 160)->nullable();
            $table->string('strength', 64)->nullable();
            $table->string('form', 48)->nullable();
            $table->string('route', 48)->nullable();
            $table->string('dose_schedule', 32)->nullable();
            $table->jsonb('dose_json')->default('{}');
            $table->smallInteger('duration_days')->nullable();
            $table->string('duration_text', 48)->nullable();
            $table->decimal('quantity', 8, 2)->nullable();
            $table->string('quantity_unit', 24)->nullable();
            $table->string('timing', 8)->default('any');
            $table->string('instruction', 255)->nullable();
            $table->string('instruction_bn', 255)->nullable();
            $table->boolean('is_continued')->default(false);
            $table->timestampsTz();

            $table->unique(['prescription_template_id', 'sort_order'], 'prescription_template_items_template_sort_order_uniq');
            $table->index(['generic_id'], 'prescription_template_items_generic_id_idx');
            $table->index(['custom_brand_id'], 'prescription_template_items_custom_brand_id_idx');
        });

        DB::statement("ALTER TABLE prescription_template_items ADD CONSTRAINT prescription_template_items_timing_check CHECK (timing IN ('before', 'after', 'with', 'any'))");
        DB::statement('ALTER TABLE prescription_template_items ADD CONSTRAINT prescription_template_items_brand_exclusive_check CHECK (NOT (brand_id IS NOT NULL AND custom_brand_id IS NOT NULL))');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_template_items');
    }
};
