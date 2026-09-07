<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `doctor_drug_usage` — monthly learning rows, incremented at issue. custom_brand_id → custom_brands(id)
// CASCADE is owned by E's later migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_drug_usage', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('icd10_code', 8)->nullable();
            $table->bigInteger('generic_id');
            $table->bigInteger('brand_id')->nullable();
            $table->bigInteger('custom_brand_id')->nullable();
            $table->bigInteger('strength_id')->nullable();
            $table->date('period_month');
            $table->integer('use_count')->default(0);
            $table->jsonb('last_dose')->default('{}');
            $table->timestampTz('last_used_at');
            $table->timestampsTz();

            $table->index(['doctor_id', 'period_month'], 'doctor_drug_usage_doctor_id_period_month_idx');
            $table->index(['custom_brand_id'], 'doctor_drug_usage_custom_brand_id_idx');
        });

        DB::statement("CREATE UNIQUE INDEX doctor_drug_usage_identity_uniq ON doctor_drug_usage (doctor_id, COALESCE(icd10_code, ''), generic_id, COALESCE(brand_id, 0), COALESCE(custom_brand_id, 0), COALESCE(strength_id, 0), period_month)");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_drug_usage');
    }
};
