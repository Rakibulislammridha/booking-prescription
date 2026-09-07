<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.2 `patient_otp_codes`: hashed one-time codes for login / booking / mobile verification. created_at only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('patient_otp_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('mobile', 20);
            $table->foreignId('patient_id')->nullable()->constrained('patients')->cascadeOnDelete();
            $table->string('purpose', 16);
            $table->string('code_hash', 255);
            $table->string('channel', 8)->default('sms');
            $table->smallInteger('attempts')->default(0);
            $table->timestampTz('expires_at');
            $table->timestampTz('consumed_at')->nullable();
            $table->ipAddress('ip')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['patient_id'], 'patient_otp_codes_patient_id_idx');
        });

        DB::statement('CREATE INDEX patient_otp_codes_mobile_purpose_created_at_idx ON patient_otp_codes (mobile, purpose, created_at DESC)');
        DB::statement("ALTER TABLE patient_otp_codes ADD CONSTRAINT patient_otp_codes_purpose_check CHECK (purpose IN ('login', 'booking', 'verify_mobile', 'consent'))");
        DB::statement("ALTER TABLE patient_otp_codes ADD CONSTRAINT patient_otp_codes_channel_check CHECK (channel IN ('sms', 'ivr', 'whatsapp'))");
        DB::statement('ALTER TABLE patient_otp_codes ADD CONSTRAINT patient_otp_codes_attempts_check CHECK (attempts <= 5)');
    }

    public function down(): void
    {
        Schema::dropIfExists('patient_otp_codes');
    }
};
