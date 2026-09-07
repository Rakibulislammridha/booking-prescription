<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'chrome' => [
        'path' => env('CHROME_PATH', '/usr/bin/google-chrome'),
    ],

    'node' => [
        'binary' => env('NODE_BINARY', 'node'),
        'npm' => env('NPM_BINARY', 'npm'),
    ],

    /*
    | Web push (RFC 8292 VAPID). One application-server key pair identifies the whole platform to the browsers'
    | push services — it is NOT tenant data. Generate a pair per deployment (`npx web-push generate-vapid-keys`,
    | or any P-256 pair encoded base64url: 65-byte public point, 32-byte private scalar) and keep the private key
    | out of the repo. Absent, App\Domain\Notifications\Services\VapidSigner reports `isConfigured() === false`
    | and the push channel falls back to the log driver, which is the correct behaviour for local and CI.
    */
    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

];
