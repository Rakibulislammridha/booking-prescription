<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.4 `visits` — the mutable clinical working record (Prescription module, engineer P).
// DEPENDENCIES (CONVENTIONS §3.1 — a 2026_02_05 file may only FK to earlier prefixes):
//   - patient_id             → patients(id) RESTRICT   (engineer E, 2026_02_06_* — the same rule as serials.patient_id /
//                                                       appointments.patient_id: plain bigint, indexed, FK owned by E's later migration)
//   - appointment_id         → appointments(id) SET NULL (engineer R, 2026_02_03_* — added below when the table exists)
//   - current_prescription_id → prescriptions(id) SET NULL (added by 2026_02_05_000800 after `prescriptions`)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visits', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('visits_public_id_uniq');
            $table->bigInteger('appointment_id')->nullable();
            $table->foreignId('serial_id')->nullable()->constrained('serials')->nullOnDelete();
            $table->foreignId('session_instance_id')->nullable()->constrained('session_instances')->nullOnDelete();
            $table->bigInteger('patient_id');
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('type', 16)->default('opd');
            $table->string('status', 10)->default('open');
            $table->timestampTz('started_at')->useCurrent();
            $table->timestampTz('ended_at')->nullable();
            $table->jsonb('chief_complaints')->default('[]');
            $table->text('examination_findings')->nullable();
            $table->jsonb('diagnoses')->default('[]');
            $table->text('private_notes')->nullable();              // ENC
            $table->date('follow_up_on')->nullable();
            $table->string('follow_up_note', 255)->nullable();
            $table->bigInteger('current_prescription_id')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['serial_id'], 'visits_serial_id_idx');
            $table->index(['session_instance_id'], 'visits_session_instance_id_idx');
            $table->index(['branch_id'], 'visits_branch_id_idx');
            $table->index(['current_prescription_id'], 'visits_current_prescription_id_idx');
            $table->index(['closed_by_user_id'], 'visits_closed_by_user_id_idx');
        });

        DB::statement('CREATE INDEX visits_patient_id_started_at_idx ON visits (patient_id, started_at DESC)');
        DB::statement('CREATE INDEX visits_doctor_id_started_at_idx ON visits (doctor_id, started_at DESC)');
        DB::statement('CREATE INDEX visits_diagnoses_gin ON visits USING gin (diagnoses jsonb_path_ops)');
        DB::statement('CREATE UNIQUE INDEX visits_appointment_id_uniq_p ON visits (appointment_id) WHERE appointment_id IS NOT NULL');
        // One visit per serial (idempotent StartVisit on SerialCalled — PRESCRIPTION.md §8; additive to SCHEMA §3.4).
        DB::statement('CREATE UNIQUE INDEX visits_serial_id_uniq_p ON visits (serial_id) WHERE serial_id IS NOT NULL');
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_type_check CHECK (type IN ('opd', 'followup', 'telemedicine'))");
        DB::statement("ALTER TABLE visits ADD CONSTRAINT visits_status_check CHECK (status IN ('open', 'closed', 'cancelled'))");

        if (Schema::hasTable('appointments')) {
            Schema::table('visits', function (Blueprint $table): void {
                $table->foreign('appointment_id', 'visits_appointment_id_foreign')->references('id')->on('appointments')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
