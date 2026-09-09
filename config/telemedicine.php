<?php

declare(strict_types=1);

// config('telemedicine.*') — the Telemedicine module's keys (CONVENTIONS §11). Platform defaults only: a clinic
// overrides provider, host, key, secret, recording and the call cap in its own `settings` rows under the
// `telemedicine.` prefix (SCHEMA Appendix B), and the secret row is encrypted before it is written
// (App\Domain\Telemedicine\Services\TelemedicineSettings).
return [
    // agora | livekit | jitsi | null. `null` is the log driver: real tokens, no network. The test suite and any
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
        'agora' => [
            // Agora has no host: the Web SDK is handed an App ID. `host` therefore overrides only the RESTful
            // base used by the Kick-User endpoint (App\Domain\Telemedicine\Providers\AgoraProvider).
            'host' => env('TELEMEDICINE_AGORA_REST_BASE', ''),   // default https://api.agora.io
            'key' => env('TELEMEDICINE_AGORA_APP_ID', ''),       // 32 hex characters
            'secret' => env('TELEMEDICINE_AGORA_APP_CERTIFICATE', ''),  // 32 hex characters
            // The account-level RESTful credential the banning endpoint authenticates with (HTTP Basic). It
            // belongs to the PLATFORM's Agora account, not to a clinic, so it is config only and never a
            // tenant settings row. Absent, `revoke`/`closeRoom` log and do nothing and the token TTL revokes.
            'rest_key' => env('TELEMEDICINE_AGORA_CUSTOMER_ID', ''),
            'rest_secret' => env('TELEMEDICINE_AGORA_CUSTOMER_SECRET', ''),
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
