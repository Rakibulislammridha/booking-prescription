<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patient_consents`: append-only consent / data-sharing log; revocation is a new row. created_at only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_consents', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('patient_id')->constrained('patients')->cascadeOnDelete();
            $table->string('type', 24);
            $table->string('status', 8);
            $table->string('policy_version', 16);
            $table->string('channel', 16);
            $table->foreignId('captured_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->ipAddress('ip')->nullable();
            $table->text('user_agent')->nullable();
            $table->text('signature_data')->nullable();        // ENC
            $table->jsonb('evidence')->default('{}');
            $table->timestampTz('occurred_at')->useCurrent();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['captured_by_user_id'], 'patient_consents_captured_by_user_id_idx');
        });

        DB::statement('CREATE INDEX patient_consents_patient_id_type_occurred_at_idx ON patient_consents (patient_id, type, occurred_at DESC)');
        DB::statement("ALTER TABLE patient_consents ADD CONSTRAINT patient_consents_type_check CHECK (type IN ('data_processing', 'data_sharing', 'sms', 'whatsapp', 'telemedicine', 'research'))");
        DB::statement("ALTER TABLE patient_consents ADD CONSTRAINT patient_consents_status_check CHECK (status IN ('granted', 'revoked'))");
        DB::statement("ALTER TABLE patient_consents ADD CONSTRAINT patient_consents_channel_check CHECK (channel IN ('counter', 'online', 'kiosk', 'app', 'phone'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_consents');
    }
};
