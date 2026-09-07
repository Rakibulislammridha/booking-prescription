<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `advice_snippets` (doctor_id NULL = clinic library).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('advice_snippets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->cascadeOnDelete();
            $table->string('shorthand', 24)->nullable();
            $table->string('category', 32)->nullable();
            $table->text('text');
            $table->text('text_bn')->nullable();
            $table->boolean('is_shared')->default(false);
            $table->integer('use_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['doctor_id'], 'advice_snippets_doctor_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX advice_snippets_owner_shorthand_uniq ON advice_snippets (COALESCE(doctor_id, 0), shorthand) WHERE shorthand IS NOT NULL');
        DB::statement("ALTER TABLE advice_snippets ADD CONSTRAINT advice_snippets_category_check CHECK (category IS NULL OR category IN ('diet', 'lifestyle', 'warning', 'followup', 'general', 'finding', 'complaint'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('advice_snippets');
    }
};
