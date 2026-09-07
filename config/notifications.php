<?php

declare(strict_types=1);

// config('notifications.*') — the Notifications module's keys, loaded by the framework like every other config
// file (CONVENTIONS §2.1 / §11: config/ is foundation-owned, modules request keys). No credential is read here:
// the platform's VAPID key pair lives in config/services.php `webpush` (the provider falls back to it), and a
// clinic's gateway credentials are encrypted rows in `sms_gateway_settings`, never config.
return [
    // Force every channel onto the log driver. Local development sets this; tests set it per test; production never does.
    'force_log_driver' => env('NOTIFICATIONS_FORCE_LOG_DRIVER', false),

    'http_timeout' => 10,

    // Bounded retry (CONVENTIONS §4: jobs set $tries/$backoff). Attempt 1 is immediate; a PERMANENT provider
    // rejection skips the rest of the ladder and dead-letters at once.
    'retry' => [
        'tries' => 4,
        'backoff' => [60, 300, 900],       // seconds: 1 min, 5 min, 15 min
    ],

    // One clinic must not exhaust a shared gateway. Counted per tenant per channel, per minute.
    'rate_limit' => [
        'per_minute' => 300,
        'retry_after' => 60,
    ],

    // Quiet hours are NOT here: they are per-clinic (`notifications.quiet_hours_{enabled,start,end}`, SCHEMA
    // Appendix B) and edited on the settings screen. NotificationEvent::isUrgent() overrides them either way — a
    // "3 ahead" call or a cancelled clinic always goes out.

    // false = opt-out (a patient is messaged unless `patient_consents` records a REVOKED consent for the channel),
    // true = opt-in (a granted consent row is required). Bangladeshi clinics operate opt-out for care messages.
    'consent' => [
        'require_explicit' => false,
    ],

    'reminders' => [
        'day_before_at' => '18:00',        // clinic-local hour the day-before reminder is sent
        'morning_at' => '07:30',
        'followup_at' => '09:00',
        'followup_lead_days' => 1,         // remind this many days before the follow-up date
        'chunk' => 200,
    ],

    'push' => [
        // Left null on purpose: the key pair is platform identity and lives in config/services.php `webpush`
        // (VAPID_PUBLIC_KEY / VAPID_PRIVATE_KEY / VAPID_SUBJECT). Set here only to override it for one install.
        'vapid' => ['public_key' => null, 'private_key' => null, 'subject' => null],
        'ttl' => 3600,
        'prune_after_failures' => 5,
    ],

    // Channels the module is allowed to use at all (a tenant plan may narrow this further in a later SaaS pass).
    'channels' => ['sms', 'whatsapp', 'push', 'email', 'ivr'],

    // Per-event delivery channels (App\Domain\Notifications\Services\EventChannels). SMS alone is the default for
    // every event: a patient who receives one cancellation as an SMS, a WhatsApp message AND a robocall is not
    // better informed, and the clinic pays three times. A clinic that wants a voice call for feature-phone
    // patients on a cancelled chamber sets 'doctor_cancelled' => ['sms', 'ivr'] here — the driver exists already.
    'event_channels' => [
        // 'doctor_cancelled' => ['sms', 'ivr'],
        // 'three_ahead' => ['sms', 'push'],
    ],

    'log' => [
        'retention_days' => 180,
    ],
];
