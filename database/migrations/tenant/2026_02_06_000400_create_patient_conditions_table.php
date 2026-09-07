<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patient_conditions`. icd10_code is a soft reference to catalog.icd10_codes.code.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_conditions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('icd10_code', 8)->nullable();
            $table->string('condition_name', 200);
            $table->string('status', 16)->default('active');
            $table->date('onset_date')->nullable();
            $table->date('resolved_date')->nullable();
            $table->text('notes')->nullable();                 // ENC
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['patient_id', 'status'], 'patient_conditions_patient_id_status_idx');
            $table->index(['recorded_by_user_id'], 'patient_conditions_recorded_by_user_id_idx');
        });

        DB::statement("ALTER TABLE patient_conditions ADD CONSTRAINT patient_conditions_status_check CHECK (status IN ('active', 'chronic', 'resolved'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_conditions');
    }
};
