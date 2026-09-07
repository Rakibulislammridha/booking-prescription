<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `prescription_items`. Snapshot text columns are the print source; generic/brand/strength ids are catalog
// soft references (validated by CatalogIdExists, scanned by catalog:reconcile). custom_brand_id → custom_brands(id)
// RESTRICT is owned by E's later migration (2026_02_06_500100 creates the table after this prefix).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_items', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('prescription_id')->constrained('prescriptions')->cascadeOnDelete();
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
            $table->string('info_url_slug', 120)->nullable();
            $table->boolean('is_continued')->default(false);
            $table->jsonb('safety_overrides')->default('[]');
            $table->timestampsTz();

            $table->unique(['prescription_id', 'sort_order'], 'prescription_items_prescription_id_sort_order_uniq');
            $table->index(['generic_id'], 'prescription_items_generic_id_idx');
            $table->index(['brand_id'], 'prescription_items_brand_id_idx');
            $table->index(['strength_id'], 'prescription_items_strength_id_idx');
            $table->index(['custom_brand_id'], 'prescription_items_custom_brand_id_idx');
        });

        DB::statement("ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_timing_check CHECK (timing IN ('before', 'after', 'with', 'any'))");
        DB::statement('ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_brand_exclusive_check CHECK (NOT (brand_id IS NOT NULL AND custom_brand_id IS NOT NULL))');
        DB::statement('ALTER TABLE prescription_items ADD CONSTRAINT prescription_items_quantity_check CHECK (quantity IS NULL OR quantity > 0)');
        DB::unprepared('CREATE TRIGGER prescription_children_immutable_trg BEFORE INSERT OR UPDATE OR DELETE ON prescription_items FOR EACH ROW EXECUTE FUNCTION public.fn_prescription_guard()');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_items');
    }
};
