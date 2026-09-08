<?php

declare(strict_types=1);

// config('telemedicine.*') — the Telemedicine module's keys (CONVENTIONS §11). Platform defaults only: a clinic
// overrides provider, host, key, secret, recording and the call cap in its own `settings` rows under the
// `telemedicine.` prefix (SCHEMA Appendix B), and the secret row is encrypted before it is written
// (App\Domain\Telemedicine\Services\TelemedicineSettings).
return [
    // livekit | jitsi | null. `null` is the log driver: real tokens, no network. The test suite and any
    // deployment without credentials run on it, which is why nothing here has a working default.
    'default' => env('TELEMEDICINE_PROVIDER', 'null'),

    // `telemedicine_rooms.provider` has a three-value CHECK and no `null` member; the log driver records as this.
    'null_driver_records_as' => 'jitsi',

    'providers' => [
        'livekit' => [
            'host' => env('TELEMEDICINE_LIVEKIT_URL', ''),       // wss://livekit.example.org (REST uses https://)
            'key' => env('TELEMEDICINE_LIVEKIT_KEY', ''),
            'secret' => env('TELEMEDICINE_LIVEKIT_SECRET', ''),
        ],
        'jitsi' => [
            'host' => env('TELEMEDICINE_JITSI_DOMAIN', ''),      // meet.example.org (self-hosted, zero cost)
            'key' => env('TELEMEDICINE_JITSI_APP_ID', ''),
            'secret' => env('TELEMEDICINE_JITSI_APP_SECRET', ''),
        ],
    ],

    // Seconds a minted join token is valid. Short on purpose: the token is the credential, the signed link is
    // only permission to ask for one, and a patient who leaves the tab open overnight re-mints on the next join.
    'token_ttl' => (int) env('TELEMEDICINE_TOKEN_TTL', 900),

    // How long the patient's signed join URL stays valid, measured from the appointment's planned start.
    'link' => [
        'valid_before_minutes' => 120,
        'valid_after_minutes' => 240,
    ],

    'room' => [
        'max_minutes' => (int) env('TELEMEDICINE_MAX_MINUTES', 45),
        'max_participants' => 4,
        'empty_timeout' => 900,
        // Recording is OFF unless a clinic turns it on AND the patient has a `telemedicine` consent row.
        'recording' => (bool) env('TELEMEDICINE_RECORDING', false),
    ],

    'http_timeout' => 10,
];
