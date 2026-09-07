<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Server-side replay log of a device's ordered offline event queue — SCHEMA §3.3 `offline_events` (OFFLINE §6–§8).
// One row per client event, exactly once: (reception_device_id, client_event_id) is the ON CONFLICT DO NOTHING target.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offline_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('reception_device_id')->constrained('reception_devices')->cascadeOnDelete();
            $table->char('client_event_id', 26);
            $table->integer('sequence_no');
            $table->string('type', 24);
            $table->char('depends_on', 26)->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('session_instance_id')->nullable()->constrained('session_instances')->nullOnDelete();
            $table->foreignId('serial_block_id')->nullable()->constrained('serial_blocks')->nullOnDelete();
            $table->jsonb('payload');
            $table->string('status', 10)->default('pending');
            $table->string('conflict_reason', 64)->nullable();
            $table->jsonb('server_result')->nullable();
            $table->string('resolution', 24)->nullable();
            $table->jsonb('resolution_params')->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('client_occurred_at');
            $table->timestampTz('received_at')->useCurrent();
            $table->timestampTz('processed_at')->nullable();

            $table->unique(['reception_device_id', 'client_event_id'], 'offline_events_reception_device_id_client_event_id_uniq');
            $table->index(['reception_device_id', 'sequence_no'], 'offline_events_reception_device_id_sequence_no_idx');
            $table->index(['reception_device_id', 'status'], 'offline_events_reception_device_id_status_idx');
            $table->index(['actor_user_id'], 'offline_events_actor_user_id_idx');
            $table->index(['session_instance_id'], 'offline_events_session_instance_id_idx');
            $table->index(['serial_block_id'], 'offline_events_serial_block_id_idx');
            $table->index(['resolved_by_user_id'], 'offline_events_resolved_by_user_id_idx');
        });

        DB::statement("CREATE INDEX offline_events_status_idx_p ON offline_events (status) WHERE status IN ('pending', 'conflict')");
        DB::statement('CREATE INDEX offline_events_depends_on_idx_p ON offline_events (depends_on) WHERE depends_on IS NOT NULL');

        DB::statement("ALTER TABLE offline_events ADD CONSTRAINT offline_events_type_check CHECK (type IN ('register_patient', 'issue_serial', 'check_in', 'collect_cash', 'print_token', 'void_local', 'mark_arrived', 'cancel_serial', 'assign_patient'))");
        DB::statement("ALTER TABLE offline_events ADD CONSTRAINT offline_events_status_check CHECK (status IN ('pending', 'accepted', 'conflict', 'rejected'))");
        DB::statement("ALTER TABLE offline_events ADD CONSTRAINT offline_events_conflict_reason_check CHECK (conflict_reason IS NULL OR conflict_reason IN ('duplicate_patient', 'patient_mismatch', 'serial_already_used', 'session_closed', 'status_regression', 'already_paid', 'dependency_unresolved', 'block_released', 'unknown_serial'))");
        DB::statement("ALTER TABLE offline_events ADD CONSTRAINT offline_events_resolution_check CHECK (resolution IS NULL OR resolution IN ('link_patient', 'family_member', 'reissue', 'move_to_session', 'record_in_closed', 'reinstate', 'refund_cash', 'credit', 'discard'))");
        DB::statement("ALTER TABLE offline_events ADD CONSTRAINT offline_events_conflict_has_reason_check CHECK (status <> 'conflict' OR conflict_reason IS NOT NULL)");
        DB::statement('ALTER TABLE offline_events ADD CONSTRAINT offline_events_resolution_resolver_check CHECK ((resolution IS NULL) = (resolved_by_user_id IS NULL))');
        DB::statement('ALTER TABLE offline_events ADD CONSTRAINT offline_events_attempts_check CHECK (attempts >= 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('offline_events');
    }
};
