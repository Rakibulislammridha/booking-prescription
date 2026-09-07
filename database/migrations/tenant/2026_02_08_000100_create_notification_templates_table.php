<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SCHEMA §3.6 `notification_templates`: per-tenant, per-event, per-channel, per-locale message bodies.
// A missing row means the built-in default of App\Domain\Notifications\Services\DefaultTemplates.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_templates', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 32);
            $table->string('channel', 10);
            $table->string('locale', 5);
            $table->string('subject', 160)->nullable();
            $table->text('body');
            $table->string('provider_template_id', 64)->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampsTz();

            $table->unique(['event_key', 'channel', 'locale'], 'notification_templates_event_key_channel_locale_uniq');
            $table->index(['updated_by_user_id'], 'notification_templates_updated_by_user_id_idx');
        });

        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_event_key_check CHECK (event_key IN ('booking_confirmed', 'reminder_day_before', 'reminder_morning', 'three_ahead', 'doctor_delayed', 'doctor_cancelled', 'prescription_ready', 'followup_due', 'otp', 'payment_receipt', 'serial_transferred', 'serial_postponed'))");
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_channel_check CHECK (channel IN ('sms', 'whatsapp', 'push', 'email', 'ivr'))");
        DB::statement("ALTER TABLE notification_templates ADD CONSTRAINT notification_templates_locale_check CHECK (locale IN ('bn', 'en'))");
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_templates');
    }
};
