<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Payment gateways
    |--------------------------------------------------------------------------
    |
    | Read through `App\Domain\Billing\Gateways\GatewayConfig`, which prefers the tenant's own
    | `billing.gateway.<gateway>.<key>` setting row and falls back to the values here. A clinic that has its own
    | merchant account configures it in the panel; these keys are the platform-level fallback (a single-tenant
    | deployment, or a reseller account shared across tenants).
    |
    | Every key may legitimately be absent: `isConfigured()` is false, `GatewayManager::available()` omits the
    | gateway, online payment is simply not offered, and an explicit attempt raises `GatewayNotConfigured`
    | instead of accepting money the app cannot verify. Nothing here is required for the suite.
    |
    | `mode` is `sandbox` unless it is exactly `live` — never default to live. `base_url` overrides the driver's
    | built-in sandbox/live host and exists for a staging proxy; leave it unset in production.
    |
    */

    'gateways' => [

        /*
        | 'log' swaps every gateway for App\Domain\Billing\Gateways\LogPaymentGateway (local development and the
        | tests, which set it per test). Unset — the default — means the real drivers, each reporting whether it
        | is configured. Never set this in production: it would accept a checkout nobody charged.
        */
        'driver' => env('BILLING_GATEWAY_DRIVER'),

        'bkash' => [
            'app_key' => env('BKASH_APP_KEY'),
            'app_secret' => env('BKASH_APP_SECRET'),
            'username' => env('BKASH_USERNAME'),
            'password' => env('BKASH_PASSWORD'),
            'mode' => env('BKASH_MODE', 'sandbox'),
            'base_url' => env('BKASH_BASE_URL'),
        ],

        'nagad' => [
            'merchant_id' => env('NAGAD_MERCHANT_ID'),
            'merchant_number' => env('NAGAD_MERCHANT_NUMBER'),
            // Base64url/PEM RSA pair issued by Nagad: `public_key` is theirs (used to encrypt), `private_key`
            // is the merchant's (used to sign). NagadGateway adds the PEM armour when it is missing.
            'public_key' => env('NAGAD_PUBLIC_KEY'),
            'private_key' => env('NAGAD_PRIVATE_KEY'),
            'mode' => env('NAGAD_MODE', 'sandbox'),
            'base_url' => env('NAGAD_BASE_URL'),
        ],

        'sslcommerz' => [
            'store_id' => env('SSLCOMMERZ_STORE_ID'),
            'store_password' => env('SSLCOMMERZ_STORE_PASSWORD'),
            'mode' => env('SSLCOMMERZ_MODE', 'sandbox'),
            'base_url' => env('SSLCOMMERZ_BASE_URL'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Coupon owner-row lock (testing only)
    |--------------------------------------------------------------------------
    |
    | `ApplyCoupon` takes FOR UPDATE on the `coupons` row before deciding whether `max_uses` /
    | `max_uses_per_patient` still allow a redemption (SERIAL_ENGINE §4, invariant I-OWNER). This flag drops that
    | lock so a concurrency test can prove the unique ordinals on `coupon_redemptions` hold the caps on their own
    | (CONVENTIONS §6.5, the same shape as `serials.testing_skip_owner_lock`). It is honoured ONLY when
    | `app()->environment('testing')`; there is no way to switch it off in production. Never set it in an .env.
    |
    */

    'testing_skip_coupon_lock' => false,

];
