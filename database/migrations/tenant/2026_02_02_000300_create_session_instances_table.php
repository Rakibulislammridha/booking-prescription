<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// The LOCKED serial scope (branch, doctor, date, session_code) — SCHEMA §3.3 `session_instances`.
// now_serving_serial_id is a plain nullable bigint here; the FK to serials is added by 2026_02_02_000800 (after `serials`).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('session_instances', function (Blueprint $table): void {
            $table->id();
            $table->ulid('public_id')->unique('session_instances_public_id_uniq');
            $table->foreignId('branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('doctor_id')->constrained('doctors')->restrictOnDelete();
            $table->date('session_date');
            $table->char('session_code', 1);
            $table->foreignId('doctor_schedule_id')->nullable()->constrained('doctor_schedules')->nullOnDelete();
            $table->string('mode', 8);
            $table->smallInteger('slot_minutes')->nullable();
            $table->string('status', 16)->default('scheduled');
            $table->timestampTz('planned_start_at');
            $table->timestampTz('planned_end_at');
            $table->timestampTz('actual_start_at')->nullable();
            $table->timestampTz('actual_end_at')->nullable();
            $table->integer('pause_seconds')->default(0);
            $table->smallInteger('delay_minutes')->default(0);
            $table->smallInteger('max_serials');
            $table->smallInteger('online_quota');
            $table->smallInteger('counter_quota');
            $table->smallInteger('buffer_quota');
            $table->integer('avg_consult_seconds');
            $table->integer('consult_samples')->default(0);
            $table->bigInteger('now_serving_serial_id')->nullable();
            $table->timestampTz('last_called_at')->nullable();
            $table->smallInteger('booked_count')->default(0);
            $table->smallInteger('checked_in_count')->default(0);
            $table->smallInteger('in_consultation_count')->default(0);
            $table->smallInteger('completed_count')->default(0);
            $table->smallInteger('no_show_count')->default(0);
            $table->smallInteger('cancelled_count')->default(0);
            $table->smallInteger('postponed_count')->default(0);
            $table->smallInteger('auto_noshow_after')->default(3);
            $table->bigInteger('fee_new_paisa');
            $table->bigInteger('fee_followup_paisa');
            $table->integer('version')->default(1);
            $table->string('cancel_reason', 255)->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notes', 255)->nullable();
            $table->timestampsTz();

            $table->unique(['branch_id', 'doctor_id', 'session_date', 'session_code'], 'session_instances_branch_id_doctor_id_session_date_session_code_uniq');
            $table->index(['session_date', 'branch_id'], 'session_instances_session_date_branch_id_idx');
            $table->index(['doctor_id', 'session_date'], 'session_instances_doctor_id_session_date_idx');
            $table->index(['doctor_schedule_id'], 'session_instances_doctor_schedule_id_idx');
            $table->index(['now_serving_serial_id'], 'session_instances_now_serving_serial_id_idx');
            $table->index(['closed_by_user_id'], 'session_instances_closed_by_user_id_idx');
        });

        DB::statement("CREATE INDEX session_instances_status_idx_p ON session_instances (status) WHERE status IN ('running', 'paused')");
        DB::statement("ALTER TABLE session_instances ADD CONSTRAINT session_instances_status_check CHECK (status IN ('scheduled', 'running', 'paused', 'closed', 'cancelled'))");
        DB::statement("ALTER TABLE session_instances ADD CONSTRAINT session_instances_mode_check CHECK (mode IN ('serial', 'slot'))");
        DB::statement("ALTER TABLE session_instances ADD CONSTRAINT session_instances_session_code_check CHECK (session_code ~ '^[A-Z]$')");
        DB::statement('ALTER TABLE session_instances ADD CONSTRAINT session_instances_max_serials_check CHECK (max_serials = online_quota + counter_quota + buffer_quota)');
        DB::statement('ALTER TABLE session_instances ADD CONSTRAINT session_instances_counts_check CHECK (booked_count >= 0 AND checked_in_count >= 0 AND in_consultation_count >= 0 AND completed_count >= 0 AND no_show_count >= 0 AND cancelled_count >= 0 AND postponed_count >= 0 AND pause_seconds >= 0)');
        DB::statement('ALTER TABLE session_instances ADD CONSTRAINT session_instances_planned_check CHECK (planned_end_at > planned_start_at)');
    }

    public function down(): void
    {
        Schema::dropIfExists('session_instances');
    }
};
