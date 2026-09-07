<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `vitals`. patient_id → patients(id) CASCADE is owned by E's later migration (see visits).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('vitals', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('visit_id')->constrained('visits')->cascadeOnDelete();
            $table->bigInteger('patient_id');
            $table->foreignId('recorded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('recorded_at')->useCurrent();
            $table->smallInteger('bp_systolic')->nullable();
            $table->smallInteger('bp_diastolic')->nullable();
            $table->smallInteger('pulse_bpm')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->smallInteger('spo2_percent')->nullable();
            $table->smallInteger('respiratory_rate')->nullable();
            $table->decimal('weight_kg', 5, 2)->nullable();
            $table->decimal('height_cm', 5, 1)->nullable();
            $table->decimal('bmi', 4, 1)->nullable();
            $table->smallInteger('blood_glucose_mgdl')->nullable();
            $table->string('notes', 255)->nullable();
            $table->boolean('edited_by_doctor')->default(false);
            $table->timestampTz('reviewed_by_doctor_at')->nullable();
            $table->timestampsTz();

            $table->index(['visit_id'], 'vitals_visit_id_idx');
            $table->index(['recorded_by_user_id'], 'vitals_recorded_by_user_id_idx');
        });

        DB::statement('CREATE INDEX vitals_patient_id_recorded_at_idx ON vitals (patient_id, recorded_at DESC)');
        DB::statement('ALTER TABLE vitals ADD CONSTRAINT vitals_bp_systolic_check CHECK (bp_systolic IS NULL OR bp_systolic BETWEEN 40 AND 300)');
        DB::statement('ALTER TABLE vitals ADD CONSTRAINT vitals_bp_diastolic_check CHECK (bp_diastolic IS NULL OR bp_diastolic BETWEEN 20 AND 200)');
        DB::statement('ALTER TABLE vitals ADD CONSTRAINT vitals_pulse_bpm_check CHECK (pulse_bpm IS NULL OR pulse_bpm BETWEEN 20 AND 300)');
        DB::statement('ALTER TABLE vitals ADD CONSTRAINT vitals_temperature_c_check CHECK (temperature_c IS NULL OR temperature_c BETWEEN 30 AND 45)');
        DB::statement('ALTER TABLE vitals ADD CONSTRAINT vitals_spo2_percent_check CHECK (spo2_percent IS NULL OR spo2_percent BETWEEN 30 AND 100)');
        DB::statement('ALTER TABLE vitals ADD CONSTRAINT vitals_weight_kg_check CHECK (weight_kg IS NULL OR weight_kg > 0)');
        DB::statement('ALTER TABLE vitals ADD CONSTRAINT vitals_height_cm_check CHECK (height_cm IS NULL OR height_cm > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('vitals');
    }
};
