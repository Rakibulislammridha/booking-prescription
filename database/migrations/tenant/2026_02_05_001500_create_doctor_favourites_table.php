<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `doctor_favourites` — ranked quick-pick per doctor per diagnosis. custom_brand_id → custom_brands(id)
// CASCADE is owned by E's later migration.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_favourites', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->string('icd10_code', 8)->nullable();
            $table->bigInteger('generic_id');
            $table->bigInteger('brand_id')->nullable();
            $table->bigInteger('custom_brand_id')->nullable();
            $table->bigInteger('strength_id')->nullable();
            $table->string('label', 200);
            $table->jsonb('default_dose')->default('{}');
            $table->boolean('is_pinned')->default(false);
            $table->integer('use_count')->default(0);
            $table->smallInteger('rank')->default(0);
            $table->timestampTz('last_used_at')->nullable();
            $table->timestampsTz();

            $table->index(['doctor_id', 'icd10_code', 'rank'], 'doctor_favourites_doctor_id_icd10_code_rank_idx');
            $table->index(['custom_brand_id'], 'doctor_favourites_custom_brand_id_idx');
        });

        DB::statement("CREATE UNIQUE INDEX doctor_favourites_identity_uniq ON doctor_favourites (doctor_id, COALESCE(icd10_code, ''), generic_id, COALESCE(brand_id, 0), COALESCE(custom_brand_id, 0))");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_favourites');
    }
};
