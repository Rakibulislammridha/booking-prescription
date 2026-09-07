<?php

declare(strict_types=1);

return [

    'ssr' => [
        'enabled' => false,
        'runtime' => 'node',
        'ensure_runtime_exists' => false,
        'url' => env('INERTIA_SSR_URL', 'http://127.0.0.1:13714'),
        'hot_url' => env('INERTIA_SSR_HOT_URL'),
        'ensure_bundle_exists' => false,
        'throw_on_error' => false,
    ],

    // Two Inertia roots (ARCHITECTURE §7.3); controllers render surface-relative page names.
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [
            resource_path('js/panel/Pages'),
            resource_path('js/site/Pages'),
        ],
        'extensions' => ['tsx'],
    ],

    'testing' => [
        'ensure_pages_exist' => true,
    ],

    'expose_shared_prop_keys' => true,

    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', false),
    ],

    'devtools' => [
        'enabled' => env('INERTIA_DEVTOOLS_ENABLED', false),
        'except' => ['telescope*', 'horizon*', '_inertia/devtools*'],
        'storage' => [
            'path' => storage_path('inertia-devtools'),
            'ttl' => (int) env('INERTIA_DEVTOOLS_TTL_HOURS', 24),
            'prune_interval' => (int) env('INERTIA_DEVTOOLS_PRUNE_INTERVAL_SECONDS', 300),
            'limit' => (int) env('INERTIA_DEVTOOLS_LIMIT', 100),
        ],
        'middleware' => ['web'],
        'gate' => env('INERTIA_DEVTOOLS_GATE'),
        'redact' => [
            'keys' => ['password', 'password_confirmation', 'current_password', 'token', '_token', 'access_token', 'refresh_token', 'secret', 'client_secret', 'api_key'],
            'headers' => ['cookie', 'set-cookie', 'authorization', 'proxy-authorization', 'x-xsrf-token', 'x-csrf-token'],
        ],
    ],

];
