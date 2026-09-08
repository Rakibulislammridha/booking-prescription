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

    // OCR naming of uploaded reports (PRESCRIPTION.md §8). Platform defaults only: a clinic overrides the driver
    // and the key in its own `patients.ocr_*` settings rows, and the key row is encrypted before it is written
    // (App\Domain\Patients\Services\OcrSettings).
    //
    // `driver` ships as 'null' and stays there unless someone pays for a cloud engine: with no driver, a PDF that
    // carries a text layer is still read locally by `pdftotext` (free, offline, exact) and only PHOTOGRAPHED
    // reports fall back to naming by type and upload date. Nothing here can make the test suite reach a network.
    'ocr' => [
        'driver' => Env::get('PATIENTS_OCR_DRIVER', 'null'),                 // null | google
        'key' => Env::get('PATIENTS_OCR_KEY', ''),                           // Google Cloud Vision API key
        'endpoint' => Env::get('PATIENTS_OCR_ENDPOINT', 'https://vision.googleapis.com/v1/images:annotate'),
        'timeout' => (int) Env::get('PATIENTS_OCR_TIMEOUT', 15),             // seconds, per HTTP attempt
        'pdftotext' => Env::get('PATIENTS_PDFTOTEXT_BIN', '/usr/bin/pdftotext'),   // '' disables the local text-layer path
        'pdftotext_timeout' => (int) Env::get('PATIENTS_PDFTOTEXT_TIMEOUT', 10),
    ],
    'policy_version' => '2026-01',
];
