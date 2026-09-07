<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.6 `notifications`: the outbound ledger — one row per recipient per channel per event.
// `dedupe_key` is the single-send guarantee (partial unique); the dispatcher index serves the due-work scan.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 32);
            $table->string('channel', 10);
            $table->foreignId('patient_id')->nullable()->constrained('patients')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('notifiable_type', 160)->nullable();
            $table->unsignedBigInteger('notifiable_id')->nullable();
            $table->foreignId('serial_id')->nullable()->constrained('serials')->nullOnDelete();
            $table->foreignId('notification_template_id')->nullable()->constrained('notification_templates')->nullOnDelete();
            $table->string('recipient', 255);
            $table->string('locale', 5);
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->jsonb('payload')->default('{}');
            $table->string('status', 10)->default('queued');
            $table->timestampTz('scheduled_for')->nullable();
            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('delivered_at')->nullable();
            $table->smallInteger('attempts')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->string('dedupe_key', 120)->nullable();
            $table->smallInteger('segments')->nullable();
            $table->bigInteger('cost_paisa')->nullable();
            $table->timestampsTz();

            $table->index(['patient_id', 'created_at'], 'notifications_patient_id_created_at_idx');
            $table->index(['notifiable_type', 'notifiable_id'], 'notifications_notifiable_type_notifiable_id_idx');
            $table->index(['user_id'], 'notifications_user_id_idx');
            $table->index(['notification_template_id'], 'notifications_notification_template_id_idx');
        });

        DB::statement('CREATE UNIQUE INDEX notifications_dedupe_key_uniq_p ON notifications (dedupe_key) WHERE dedupe_key IS NOT NULL');
        DB::statement("CREATE INDEX notifications_status_scheduled_for_idx_p ON notifications (status, scheduled_for) WHERE status IN ('queued', 'scheduled')");
        DB::statement('CREATE INDEX notifications_serial_id_event_key_created_at_idx ON notifications (serial_id, event_key, created_at DESC)');
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_event_key_check CHECK (event_key IN ('booking_confirmed', 'reminder_day_before', 'reminder_morning', 'three_ahead', 'doctor_delayed', 'doctor_cancelled', 'prescription_ready', 'followup_due', 'otp', 'payment_receipt', 'serial_transferred', 'serial_postponed'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_channel_check CHECK (channel IN ('sms', 'whatsapp', 'push', 'email', 'ivr'))");
        DB::statement("ALTER TABLE notifications ADD CONSTRAINT notifications_status_check CHECK (status IN ('queued', 'scheduled', 'sending', 'sent', 'delivered', 'failed', 'cancelled'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
