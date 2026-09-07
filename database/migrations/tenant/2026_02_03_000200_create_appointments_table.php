<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The booking of every channel — SCHEMA §3.3 `appointments`, fee snapshot per §5.10.
// DEPENDENCIES (columns exist as plain bigints, indexed; the FK is added by the owning module's LATER migration,
// CONVENTIONS §3.1 — a 2026_02_03 file may only reference tables with an earlier prefix):
//   - patient_id            → patients(id) RESTRICT   (engineer E, 2026_02_06_* — the same rule as serials.patient_id)
//   - follow_up_of_visit_id → visits(id) SET NULL     (engineer P, 2026_02_05_*)
//   - invoice_id            → invoices(id) SET NULL   (engineer B, 2026_02_07_*)
// DEVIATION (documented in the R report): SCHEMA's `(type='followup') = (follow_up_of_visit_id IS NOT NULL)` is
// relaxed to `follow_up_of_visit_id IS NULL OR type = 'followup'` — visits do not exist yet, so the free follow-up
// window is resolved from the patient's last completed appointment with the same doctor (FeeResolver) and a
// follow-up may carry no visit reference until the Prescription module ships. P may tighten the CHECK then.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('appointments_public_id_uniq');
            $table->foreignId('tenant_id')->constrained('public.tenants')->restrictOnDelete();
            $table->bigInteger('patient_id');
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('session_instance_id')->nullable()->constrained('session_instances')->restrictOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('serials')->nullOnDelete();
            $table->string('type', 10);
            $table->string('channel', 16);
            $table->string('status', 16)->default('pending');
            $table->date('scheduled_date')->nullable();
            $table->timestampTz('slot_start_at')->nullable();
            $table->bigInteger('list_fee_paisa');
            $table->bigInteger('fee_paisa');
            $table->string('fee_rule', 20);
            $table->string('fee_rule_reason', 255)->nullable();
            $table->string('payment_status', 10)->default('unpaid');
            $table->bigInteger('invoice_id')->nullable();
            $table->bigInteger('follow_up_of_visit_id')->nullable();
            $table->foreignId('booked_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('booked_by_patient')->default(false);
            $table->boolean('is_telemedicine')->default(false);
            $table->string('notes', 500)->nullable();
            $table->string('idempotency_key', 64)->nullable();
            $table->char('client_event_id', 26)->nullable();
            $table->foreignId('reception_device_id')->nullable()->constrained('reception_devices')->nullOnDelete();
            $table->string('cancel_reason_code', 24)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->foreignId('cancelled_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('reminder_day_before_sent_at')->nullable();
            $table->timestampTz('reminder_morning_sent_at')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id'], 'appointments_tenant_id_idx');
            $table->index(['session_instance_id', 'status'], 'appointments_session_instance_id_status_idx');
            $table->index(['doctor_id', 'scheduled_date'], 'appointments_doctor_id_scheduled_date_idx');
            $table->index(['branch_id'], 'appointments_branch_id_idx');
            $table->index(['invoice_id'], 'appointments_invoice_id_idx');
            $table->index(['follow_up_of_visit_id'], 'appointments_follow_up_of_visit_id_idx');
            $table->index(['booked_by_user_id'], 'appointments_booked_by_user_id_idx');
            $table->index(['cancelled_by_user_id'], 'appointments_cancelled_by_user_id_idx');
            $table->index(['reception_device_id'], 'appointments_reception_device_id_idx');
        });

        DB::statement('CREATE INDEX appointments_patient_id_scheduled_date_idx ON appointments (patient_id, scheduled_date DESC)');
        DB::statement('CREATE UNIQUE INDEX appointments_serial_id_uniq_p ON appointments (serial_id) WHERE serial_id IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX appointments_idempotency_key_uniq_p ON appointments (idempotency_key) WHERE idempotency_key IS NOT NULL');
        DB::statement('CREATE UNIQUE INDEX appointments_reception_device_id_client_event_id_uniq_p ON appointments (reception_device_id, client_event_id) WHERE client_event_id IS NOT NULL');
        DB::statement("CREATE UNIQUE INDEX appointments_patient_id_session_instance_id_uniq_p ON appointments (patient_id, session_instance_id) WHERE status NOT IN ('cancelled', 'no_show')");
        DB::statement("CREATE INDEX appointments_status_scheduled_date_idx_p ON appointments (status, scheduled_date) WHERE status = 'draft'");

        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_type_check CHECK (type IN ('new', 'followup'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_channel_check CHECK (channel IN ('online', 'phone', 'counter', 'walkin', 'kiosk', 'followup', 'telemedicine', 'offline'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_status_check CHECK (status IN ('draft', 'pending', 'confirmed', 'checked_in', 'in_consultation', 'completed', 'no_show', 'cancelled', 'postponed'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_fee_rule_check CHECK (fee_rule IN ('new', 'followup_paid', 'followup_free', 'schedule_override', 'telemedicine', 'manual', 'waived'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_payment_status_check CHECK (payment_status IN ('unpaid', 'partial', 'paid', 'refunded'))");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_cancel_reason_code_check CHECK (cancel_reason_code IS NULL OR cancel_reason_code IN ('patient_request', 'doctor_unavailable', 'duplicate', 'transferred', 'no_payment', 'session_cancelled', 'other'))");
        DB::statement('ALTER TABLE appointments ADD CONSTRAINT appointments_fee_paisa_check CHECK (fee_paisa >= 0)');
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_draft_session_check CHECK (status = 'draft' OR session_instance_id IS NOT NULL)");
        DB::statement("ALTER TABLE appointments ADD CONSTRAINT appointments_followup_visit_check CHECK (follow_up_of_visit_id IS NULL OR type = 'followup')");
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
