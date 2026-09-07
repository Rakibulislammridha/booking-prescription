<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Immutable log of every serial transition, reorder, priority change, transfer and session-level pool/block event — SCHEMA §3.3 `serial_events`.
// DEPENDENCIES (plain nullable bigints, indexed; FKs added by the owning module):
//   - actor_patient_id    → patients(id) SET NULL          (engineer E, 2026_02_06_*)
//   - reception_device_id → reception_devices(id) SET NULL (engineer R, 2026_02_03_*)
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('serial_id')->nullable()->constrained('serials')->cascadeOnDelete();
            $table->foreignId('session_instance_id')->constrained('session_instances')->cascadeOnDelete();
            $table->string('type', 32);
            $table->string('from_status', 16)->nullable();
            $table->string('to_status', 16)->nullable();
            $table->bigInteger('from_position')->nullable();
            $table->bigInteger('to_position')->nullable();
            $table->string('from_priority', 10)->nullable();
            $table->string('to_priority', 10)->nullable();
            $table->string('actor_type', 8);
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->bigInteger('actor_patient_id')->nullable();
            $table->bigInteger('reception_device_id')->nullable();
            $table->char('client_event_id', 26)->nullable();
            $table->string('reason', 255)->nullable();
            $table->jsonb('meta')->default('{}');
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('occurred_at')->useCurrent();

            $table->index(['serial_id', 'occurred_at'], 'serial_events_serial_id_occurred_at_idx');
            $table->index(['session_instance_id', 'occurred_at'], 'serial_events_session_instance_id_occurred_at_idx');
            $table->index(['actor_user_id'], 'serial_events_actor_user_id_idx');
            $table->index(['actor_patient_id'], 'serial_events_actor_patient_id_idx');
            $table->index(['reception_device_id'], 'serial_events_reception_device_id_idx');
        });

        DB::statement('CREATE INDEX serial_events_session_instance_id_type_idx_p ON serial_events (session_instance_id, type) WHERE serial_id IS NULL');
        DB::statement("ALTER TABLE serial_events ADD CONSTRAINT serial_events_type_check CHECK (type IN ('booked', 'checked_in', 'called', 'consultation_started', 'completed', 'no_show', 'reinstated', 'reinstated_after_cancel', 'cancelled', 'postponed', 'reordered', 'priority_changed', 'skipped', 'transferred_out', 'transferred_in', 'patient_assigned', 'note_added', 'printed', 'recorded_post_close', 'number_skipped', 'renormalised', 'capacity_extended', 'online_released', 'split_changed', 'block_leased', 'block_released', 'block_revoked', 'delayed', 'void_local'))");
        DB::statement("ALTER TABLE serial_events ADD CONSTRAINT serial_events_actor_type_check CHECK (actor_type IN ('user', 'patient', 'device', 'system'))");
        DB::statement("ALTER TABLE serial_events ADD CONSTRAINT serial_events_serial_id_check CHECK (serial_id IS NOT NULL OR type IN ('number_skipped', 'renormalised', 'capacity_extended', 'online_released', 'split_changed', 'block_leased', 'block_released', 'block_revoked', 'delayed', 'void_local'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_events');
    }
};
