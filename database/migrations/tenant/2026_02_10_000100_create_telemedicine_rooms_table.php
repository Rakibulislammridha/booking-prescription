<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.8 `telemedicine_rooms` — one room per telemedicine appointment (Module K, engineer T).
// DEPENDENCIES (CONVENTIONS §3.1 — a 2026_02_10 file may FK to every earlier prefix):
//   - appointment_id → appointments(id) CASCADE (engineer R, 2026_02_03_*)
// `room_name` is the provider's room id and is deliberately unguessable (`t{tenantId}-{ulid}`): it is the only
// identifier that ever appears in a patient's join URL, so it doubles as the row's public handle.
// Join TOKENS are never stored — only the expiry of the URL that lets a participant ask for one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemedicine_rooms', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('appointment_id')->constrained('appointments')->cascadeOnDelete();
            $table->string('provider', 10);
            $table->string('room_name', 120);
            $table->string('status', 10)->default('scheduled');
            $table->timestampTz('scheduled_at');
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('ended_at')->nullable();
            $table->timestampTz('doctor_join_url_expires_at')->nullable();
            $table->timestampTz('patient_join_url_expires_at')->nullable();
            $table->jsonb('settings')->default('{}');
            $table->timestampsTz();

            $table->unique(['appointment_id'], 'telemedicine_rooms_appointment_id_uniq');
            $table->unique(['room_name'], 'telemedicine_rooms_room_name_uniq');
            $table->index(['status', 'scheduled_at'], 'telemedicine_rooms_status_scheduled_at_idx');
        });

        DB::statement("ALTER TABLE telemedicine_rooms ADD CONSTRAINT telemedicine_rooms_provider_check CHECK (provider IN ('agora', 'livekit', 'jitsi'))");
        DB::statement("ALTER TABLE telemedicine_rooms ADD CONSTRAINT telemedicine_rooms_status_check CHECK (status IN ('scheduled', 'open', 'ended', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('telemedicine_rooms');
    }
};
