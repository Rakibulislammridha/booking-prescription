<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `prescription_templates` (soft delete; doctor_id NULL = clinic-shared).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->nullable()->constrained('doctors')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('shorthand', 24)->nullable();
            $table->string('icd10_code', 8)->nullable();
            $table->string('diagnosis_title', 200)->nullable();
            $table->boolean('is_shared')->default(false);
            $table->jsonb('body')->default('{}');
            $table->integer('use_count')->default(0);
            $table->timestampTz('last_used_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();
            $table->softDeletesTz();

            $table->index(['doctor_id'], 'prescription_templates_doctor_id_idx');
            $table->index(['created_by_user_id'], 'prescription_templates_created_by_user_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX prescription_templates_owner_name_uniq ON prescription_templates (COALESCE(doctor_id, 0), lower(name)) WHERE deleted_at IS NULL');
        DB::statement('CREATE UNIQUE INDEX prescription_templates_doctor_id_shorthand_uniq_p ON prescription_templates (doctor_id, shorthand) WHERE shorthand IS NOT NULL AND deleted_at IS NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_templates');
    }
};
