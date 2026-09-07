<?php

declare(strict_types=1);

use Illuminate\Support\Env;

// config('patients.*') — moved here from app/Domain/Patients/config.php by the foundation (config/* is foundation-owned);
// the Patients module still owns its contents.
return [
    'otp' => [
        'fixed_code' => Env::get('OTP_FIXED_CODE'),   // honoured in local/testing only (ARCHITECTURE §6.3); read at config-cache time like config/*
        'ttl_seconds' => 300,
        'resend_seconds' => 60,
        'max_attempts' => 5,
    ],
    'documents' => [
        'disk' => 'uploads',
        'max_kb' => 10240,
        'mimes' => ['jpg', 'jpeg', 'png', 'webp', 'pdf'],
    ],
    'policy_version' => '2026-01',
];
