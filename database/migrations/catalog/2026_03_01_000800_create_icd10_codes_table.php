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
        Schema::create('icd10_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 8);
            $table->string('title', 255);
            $table->string('title_bn', 255)->nullable();
            $table->string('chapter', 8)->nullable();
            $table->string('block', 16)->nullable();
            $table->string('parent_code', 8)->nullable();
            $table->jsonb('aliases')->default('[]');
            $table->boolean('is_billable')->default(true);
            $table->foreignId('catalog_version_id')->nullable()->constrained('catalog_versions')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['code'], 'icd10_codes_code_uniq');
            $table->index(['parent_code'], 'icd10_codes_parent_code_idx');
            $table->index(['catalog_version_id'], 'icd10_codes_catalog_version_id_idx');
            $table->foreign('parent_code', 'icd10_codes_parent_code_foreign')->references('code')->on('icd10_codes')->nullOnDelete();
        });

        DB::statement('CREATE INDEX icd10_codes_title_trgm ON icd10_codes USING gin (title gin_trgm_ops)');
    }

    public function down(): void
    {
        Schema::dropIfExists('icd10_codes');
    }
};
