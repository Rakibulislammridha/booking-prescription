<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// Per-date deviations applied at materialisation or, when the instance exists, immediately — SCHEMA §3.3 `schedule_overrides`.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_overrides', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('doctor_id')->constrained('doctors')->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained('branches')->cascadeOnDelete();
            $table->date('override_date');
            $table->char('session_code', 1)->nullable();
            $table->string('type', 16);
            $table->smallInteger('delay_minutes')->nullable();
            $table->time('new_start_time')->nullable();
            $table->time('new_end_time')->nullable();
            $table->smallInteger('new_max_serials')->nullable();
            $table->smallInteger('new_online_quota')->nullable();
            $table->smallInteger('new_counter_quota')->nullable();
            $table->smallInteger('new_buffer_quota')->nullable();
            $table->string('reason', 255)->nullable();
            $table->boolean('notify_patients')->default(true);
            $table->timestampTz('applied_at')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->index(['doctor_id', 'branch_id', 'override_date'], 'schedule_overrides_doctor_id_branch_id_override_date_idx');
            $table->index(['branch_id'], 'schedule_overrides_branch_id_idx');
            $table->index(['created_by_user_id'], 'schedule_overrides_created_by_user_id_idx');
        });

        DB::statement("ALTER TABLE schedule_overrides ADD CONSTRAINT schedule_overrides_type_check CHECK (type IN ('late_start', 'cut_short', 'cancelled', 'capacity_change', 'time_change', 'extra_session'))");
        DB::statement("ALTER TABLE schedule_overrides ADD CONSTRAINT schedule_overrides_session_code_check CHECK (session_code IS NULL OR session_code ~ '^[A-Z]$')");
        DB::statement('ALTER TABLE schedule_overrides ADD CONSTRAINT schedule_overrides_new_max_serials_check CHECK (new_max_serials IS NULL OR new_max_serials = new_online_quota + new_counter_quota + new_buffer_quota)');
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_overrides');
    }
};
