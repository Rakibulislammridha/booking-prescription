<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Weekly recurring template: one row per (doctor, branch, weekday, session_code) — SCHEMA §3.3 `doctor_schedules`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->smallInteger('weekday');
            $table->char('session_code', 1);
            $table->string('session_label', 32)->nullable();
            $table->time('start_time');
            $table->time('end_time');
            $table->string('mode', 8)->default('serial');
            $table->smallInteger('slot_minutes')->nullable();
            $table->smallInteger('max_serials');
            $table->smallInteger('online_quota');
            $table->smallInteger('counter_quota');
            $table->smallInteger('buffer_quota')->default(4);
            $table->smallInteger('avg_consult_minutes')->default(6);
            $table->bigInteger('fee_new_paisa')->nullable();
            $table->bigInteger('fee_followup_paisa')->nullable();
            $table->smallInteger('auto_noshow_after')->nullable();
            $table->boolean('works_on_holidays')->default(false);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['doctor_id', 'branch_id', 'weekday', 'session_code', 'effective_from'], 'doctor_schedules_doctor_id_branch_id_weekday_session_code_effective_from_uniq');
            $table->index(['branch_id'], 'doctor_schedules_branch_id_idx');
        });

        DB::statement('CREATE INDEX doctor_schedules_doctor_id_weekday_idx_p ON doctor_schedules (doctor_id, weekday) WHERE is_active');
        DB::statement('ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_weekday_check CHECK (weekday BETWEEN 0 AND 6)');
        DB::statement("ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_session_code_check CHECK (session_code ~ '^[A-Z]$')");
        DB::statement("ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_mode_check CHECK (mode IN ('serial', 'slot'))");
        DB::statement('ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_time_check CHECK (end_time > start_time)');
        DB::statement('ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_max_serials_check CHECK (max_serials = online_quota + counter_quota + buffer_quota)');
        DB::statement('ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_quotas_check CHECK (online_quota >= 0 AND counter_quota >= 0 AND buffer_quota >= 0)');
        DB::statement("ALTER TABLE doctor_schedules ADD CONSTRAINT doctor_schedules_slot_minutes_check CHECK (mode <> 'slot' OR slot_minutes > 0)");
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedules');
    }
};
