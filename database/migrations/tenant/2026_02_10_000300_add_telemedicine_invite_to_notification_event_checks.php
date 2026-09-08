<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * SCHEMA §3.6 `notifications.event_key` / `notification_templates.event_key` gain `telemedicine_invite`
 * (SCHEMA §0.2: "adding a value = one migration that drops and recreates the CHECK, plus the enum case").
 *
 * It is added here, under the Telemedicine prefix, rather than by editing the Notifications module's own
 * 2026_02_08 migration, which is already on `main` (CONVENTIONS §3.1: never edit a merged migration; a later
 * module that needs a change to an earlier module's table ships its own).
 *
 * WHY the catalogue needs a thirteenth event at all: every other message names a place and a time, and a patient
 * finds their way there. A video consultation has no place — the link IS the appointment, and `booking_confirmed`
 * has no `{{link}}` in its body (correctly: an in-person booking has nothing to link to).
 */
return new class extends Migration
{
    private const EVENTS = [
        'booking_confirmed', 'reminder_day_before', 'reminder_morning', 'three_ahead', 'doctor_delayed',
        'doctor_cancelled', 'prescription_ready', 'followup_due', 'otp', 'payment_receipt',
        'serial_transferred', 'serial_postponed', 'telemedicine_invite',
    ];

    public function up(): void
    {
        foreach (['notifications', 'notification_templates'] as $table) {
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_event_key_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_event_key_check CHECK (event_key IN ('".implode("', '", self::EVENTS)."'))");
        }
    }

    public function down(): void
    {
        $previous = array_filter(self::EVENTS, fn (string $e): bool => $e !== 'telemedicine_invite');

        foreach (['notifications', 'notification_templates'] as $table) {
            DB::statement("DELETE FROM {$table} WHERE event_key = 'telemedicine_invite'");
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$table}_event_key_check");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_event_key_check CHECK (event_key IN ('".implode("', '", $previous)."'))");
        }
    }
};
