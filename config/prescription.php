<?php

declare(strict_types=1);

use App\Domain\Prescription\Safety\Checks;

// config('prescription.*') — the Prescription module's keys, loaded by the framework like every other config file
// (CONVENTIONS §2.1 / §11: config/ is foundation-owned, modules request keys). It used to be merged from
// app/Domain/Prescription/config.php, which is why the environment-dependent values below were expressed as
// `null` sentinels; now that it lives here they are plain env() calls.
return [
    'safety' => [
        'checks' => [
            Checks\CatalogReferenceCheck::class,
            Checks\CustomBrandLinkCheck::class,
            Checks\AllergyCheck::class,
            Checks\InteractionCheck::class,
            Checks\DuplicateTherapyCheck::class,
            Checks\PediatricDoseCheck::class,
            Checks\MaxDailyDoseCheck::class,
            Checks\PregnancyLactationCheck::class,
            Checks\RenalHepaticCheck::class,
        ],
        'override_min_reason_chars' => 10,
    ],
    'cont_days' => 30,                                   // "Continue" quantity assumption when doctor_profiles.prefs.cont_days is unset
    'cache_store' => env('PRESCRIPTION_CACHE_STORE'),    // per-doctor learning caches (null = default store: redis in production, array in tests)
    'uploads_disk' => 'uploads',                         // handwriting / drawing PNGs (ARCHITECTURE §8.7)
    'pdfs_disk' => 'pdfs',
    'features' => ['voice' => true, 'handwriting' => true],
    'drawing_backgrounds' => ['blank', 'dental_adult', 'dental_child', 'eye_pair', 'skeleton_front', 'body_front_back', 'spine', 'abdomen'],
    'verify_throttle_per_minute' => 30,                  // throttle:rx-verify (PRESCRIPTION.md §7.4)
    'search_throttle_per_second' => 20,
    'pdf' => [
        // Unset → config('services.chrome.path') (CHROME_PATH). Only a deployment that renders prescriptions with
        // a different Chrome from the rest of the app needs to set this.
        'chrome_path' => env('PRESCRIPTION_CHROME_PATH'),
        'timeout' => 60,                                     // seconds Browsershot waits for the whole render
        'protocol_timeout' => 60000,                         // ms for a single CDP call
        // Queue the PDF when a prescription is issued (§7.5). phpunit.xml sets PRESCRIPTION_PDF_ON_ISSUE=false:
        // the suite runs the `sync` queue, so every issue in every feature test would otherwise shell out to
        // headless Chrome. The tests that mean it turn it back on with config([...]) explicitly.
        'on_issue' => env('PRESCRIPTION_PDF_ON_ISSUE', true),
    ],
    'ai' => ['timeout' => 8],
    'views' => ['verify' => 'site.rx.show', 'drug' => 'site.drug.show'],   // P3's Blade pages (resources/views/site/{rx,drug}/show.blade.php); JSON until they exist
];
