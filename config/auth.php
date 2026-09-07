<?php

declare(strict_types=1);
use App\Models\Central\SuperAdmin;
use App\Models\Tenant\User;

return [

    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    // ARCHITECTURE §6.1. 'sanctum' is registered by Sanctum (bearer token, else config('sanctum.guard') = ['web']).
    'guards' => [
        'web' => ['driver' => 'session', 'provider' => 'staff'],
        'patient' => ['driver' => 'session', 'provider' => 'patients'],
        'super' => ['driver' => 'session', 'provider' => 'super_admins'],
        'device' => ['driver' => 'sanctum', 'provider' => 'reception_devices'],
    ],

    // 'tenant' = App\Auth\TenantUserProvider (registered in AuthServiceProvider): Eloquent, but answers null instead
    // of throwing TenancyNotInitialized when no tenant is active (a stray login_web_* key on a central host).
    'providers' => [
        'staff' => ['driver' => 'tenant', 'model' => User::class],
        'patients' => ['driver' => 'tenant', 'model' => 'App\\Models\\Tenant\\Patient'],
        'super_admins' => ['driver' => 'eloquent', 'model' => SuperAdmin::class],
        // The model is created by the Reception module; the provider is resolved lazily so the app boots without it.
        'reception_devices' => ['driver' => 'tenant', 'model' => 'App\\Models\\Tenant\\ReceptionDevice'],
    ],

    'passwords' => [
        'users' => [
            'provider' => 'staff',
            'table' => 'password_reset_tokens',            // tenant schema, bare name
            'expire' => 60,
            'throttle' => 60,
        ],
        'super_admins' => [
            'provider' => 'super_admins',
            'table' => 'public.password_reset_tokens',     // qualified: the tenant search path has no public fallback
            'expire' => 60,
            'throttle' => 60,
        ],
    ],

    'password_timeout' => env('AUTH_PASSWORD_TIMEOUT', 10800),

];
