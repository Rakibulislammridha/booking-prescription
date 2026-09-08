<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.8 `telemedicine_sessions` — one row per actual call attempt inside a room (a reconnect opens a new
// row). `visit_id` is the ORDINARY visit the ordinary prescription writer opens: a video consultation ends in the
// same `visits` → `prescriptions` flow as an in-person one (BRIEF §5.K). `recording_path` is ENC (SCHEMA §5.5).
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemedicine_sessions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('telemedicine_room_id')->constrained('telemedicine_rooms')->cascadeOnDelete();
            $table->foreignId('visit_id')->nullable()->constrained('visits')->nullOnDelete();
            $table->timestampTz('started_at');
            $table->timestampTz('ended_at')->nullable();
            $table->integer('duration_seconds')->nullable();
            $table->string('end_reason', 10)->nullable();
            $table->jsonb('participants')->default('[]');
            $table->jsonb('quality')->nullable();
            $table->text('recording_path')->nullable();              // ENC
            $table->string('provider_session_id', 128)->nullable();
            $table->timestampsTz();

            $table->index(['telemedicine_room_id', 'started_at'], 'telemedicine_sessions_room_started_idx');
        });

        DB::statement("ALTER TABLE telemedicine_sessions ADD CONSTRAINT telemedicine_sessions_end_reason_check CHECK (end_reason IS NULL OR end_reason IN ('completed', 'dropped', 'no_show', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_sessions');
    }
};
