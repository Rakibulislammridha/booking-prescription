<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// One issued token in a session; `serials_session_number_uniq (session_instance_id, number)` is the duplicate guard — SCHEMA §3.3 `serials`.
// DEPENDENCIES (columns exist as plain nullable bigints, indexed; the FK is added by the owning module's later migration):
//   - appointment_id      → appointments(id) SET NULL      (engineer R, 2026_02_03_*)
//   - reception_device_id → reception_devices(id) SET NULL (engineer R, 2026_02_03_*)
//   - patient_id          → patients(id) RESTRICT          (engineer E, 2026_02_06_*)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serials', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('serials_public_id_uniq');
            $table->foreignId('session_instance_id')->constrained('session_instances')->restrictOnDelete();
            $table->integer('number');
            $table->string('display_code', 8);
            $table->bigInteger('position');
            $table->string('pool', 8);
            $table->string('status', 16)->default('booked');
            $table->string('priority', 10)->default('normal');
            $table->string('source', 10);
            $table->bigInteger('appointment_id')->nullable();
            $table->bigInteger('patient_id')->nullable();
            $table->foreignId('serial_block_id')->nullable()->constrained('serial_blocks')->nullOnDelete();
            $table->bigInteger('reception_device_id')->nullable();
            $table->char('client_event_id', 26)->nullable();
            $table->foreignId('transferred_from_serial_id')->nullable()->constrained('serials')->nullOnDelete();
            $table->foreignId('transferred_to_serial_id')->nullable()->constrained('serials')->nullOnDelete();
            $table->foreignId('postponed_to_serial_id')->nullable()->constrained('serials')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('slot_start_at')->nullable();
            $table->timestampTz('booked_at')->useCurrent();
            $table->timestampTz('checked_in_at')->nullable();
            $table->timestampTz('called_at')->nullable();
            $table->timestampTz('consultation_started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestampTz('no_show_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('postponed_at')->nullable();
            $table->string('cancel_reason_code', 24)->nullable();
            $table->timestampTz('reinstated_at')->nullable();
            $table->timestampTz('t3_notified_at')->nullable();
            $table->smallInteger('passed_count')->default(0);
            $table->smallInteger('skip_count')->default(0);
            $table->timestampTz('estimated_call_at')->nullable();
            $table->string('notes', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['session_instance_id', 'number'], 'serials_session_number_uniq');
            $table->index(['session_instance_id', 'position', 'number'], 'serials_session_instance_id_position_number_idx');
            $table->index(['session_instance_id', 'status'], 'serials_session_instance_id_status_idx');
            $table->index(['patient_id', 'booked_at'], 'serials_patient_id_booked_at_idx');
            $table->index(['serial_block_id'], 'serials_serial_block_id_idx');
            $table->index(['reception_device_id'], 'serials_reception_device_id_idx');
            $table->index(['transferred_from_serial_id'], 'serials_transferred_from_serial_id_idx');
            $table->index(['transferred_to_serial_id'], 'serials_transferred_to_serial_id_idx');
            $table->index(['postponed_to_serial_id'], 'serials_postponed_to_serial_id_idx');
            $table->index(['issued_by_user_id'], 'serials_issued_by_user_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX serials_session_client_event_uniq ON serials (session_instance_id, client_event_id) WHERE client_event_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX serials_reception_device_id_client_event_id_uniq_p ON serials (reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX serials_session_instance_id_slot_start_at_uniq_p ON serials (session_instance_id, slot_start_at) WHERE slot_start_at IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX serials_appointment_id_uniq_p ON serials (appointment_id) WHERE appointment_id IS NOT NULL');
        DB::statement("CREATE INDEX serials_session_instance_id_position_idx_p ON serials (session_instance_id, position) WHERE status IN ('booked', 'checked_in', 'in_consultation')");
        DB::statement('DROP INDEX IF EXISTS serials_patient_id_booked_at_idx');
        DB::statement('CREATE INDEX serials_patient_id_booked_at_idx ON serials (patient_id, booked_at DESC)');

        DB::statement("ALTER TABLE serials ADD CONSTRAINT serials_pool_check CHECK (pool IN ('online', 'counter', 'buffer'))");
        DB::statement("ALTER TABLE serials ADD CONSTRAINT serials_status_check CHECK (status IN ('booked', 'checked_in', 'in_consultation', 'completed', 'no_show', 'cancelled', 'postponed'))");
        DB::statement("ALTER TABLE serials ADD CONSTRAINT serials_priority_check CHECK (priority IN ('normal', 'elderly', 'emergency', 'vip'))");
        DB::statement("ALTER TABLE serials ADD CONSTRAINT serials_source_check CHECK (source IN ('online', 'counter', 'walkin', 'kiosk', 'followup', 'offline'))");
        DB::statement("ALTER TABLE serials ADD CONSTRAINT serials_cancel_reason_code_check CHECK (cancel_reason_code IS NULL OR cancel_reason_code IN ('patient_request', 'doctor_unavailable', 'duplicate', 'transferred', 'no_payment', 'session_cancelled', 'other'))");
        DB::statement('ALTER TABLE serials ADD CONSTRAINT serials_number_check CHECK (number >= 1)');
        DB::statement('ALTER TABLE serials ADD CONSTRAINT serials_position_check CHECK (position >= 0)');
        DB::statement('ALTER TABLE serials ADD CONSTRAINT serials_passed_count_check CHECK (passed_count >= 0)');
        DB::statement('ALTER TABLE serials ADD CONSTRAINT serials_skip_count_check CHECK (skip_count >= 0)');
        DB::statement("ALTER TABLE serials ADD CONSTRAINT serials_serial_block_id_check CHECK (serial_block_id IS NULL OR source = 'offline')");
    }

    public function down(): void
    {
        Schema::dropIfExists('serials');
    }
};
