<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Clinic\Services\Settings;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-clinic OCR credentials with config fallbacks (CONVENTIONS §11: tenant-editable settings are rows, platform
 * defaults are config). One clinic paying for Vision must not enable it for every other tenant on the box, so the
 * tenant row wins over `config('patients.ocr.*')` and a clinic that has set nothing inherits the platform's
 * driver — which ships as `null`.
 *
 * `patients.ocr_api_key` holds a Laravel-ENCRYPTED string written by storeApiKey(): `settings.value` is plain
 * jsonb and a paid API key has no business sitting there in clear text (the same rule the telemedicine secret
 * follows). Reading tolerates a plain value so a developer pasting a key by hand is not met with a stack trace.
 */
final class OcrSettings
{
    public const KEY_DRIVER = 'patients.ocr_driver';

    public const KEY_API_KEY = 'patients.ocr_api_key';

    /** @var array<int, string> */
    private const DRIVERS = ['null', 'google'];

    public function __construct(private readonly Settings $settings) {}

    /** 'null' | 'google'. 'default' (or nothing) on the tenant row means "follow the platform". */
    public function driver(): string
    {
        $stored = $this->raw(self::KEY_DRIVER);
        $value = is_string($stored) && $stored !== '' && $stored !== 'default'
            ? $stored
            : (string) config('patients.ocr.driver', 'null');

        return in_array($value, self::DRIVERS, true) ? $value : 'null';
    }

    /** The clinic's own key if it has one, else the platform's; '' means "not configured". */
    public function apiKey(): string
    {
        $stored = $this->raw(self::KEY_API_KEY);

        if (! is_string($stored) || trim($stored) === '') {
            return trim((string) config('patients.ocr.key', ''));
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return trim($stored);                 // written by hand, never encrypted: usable, and the panel re-encrypts on save
        }
    }

    public function endpoint(): string
    {
        $endpoint = trim((string) config('patients.ocr.endpoint', GoogleVisionOcrEngine::DEFAULT_ENDPOINT));

        return $endpoint !== '' ? $endpoint : GoogleVisionOcrEngine::DEFAULT_ENDPOINT;
    }

    public function timeout(): int
    {
        $timeout = (int) config('patients.ocr.timeout', 15);

        return $timeout > 0 ? $timeout : 15;
    }

    /** What a settings screen writes: the key never reaches the row in clear text. */
    public function storeApiKey(string $plain): void
    {
        $this->settings->set(self::KEY_API_KEY, $plain === '' ? '' : Crypt::encryptString($plain));
    }

    /** Settings are tenant rows; a central-context call (a console command, say) sees the platform config only. */
    private function raw(string $key): mixed
    {
        return Tenancy::check() ? $this->settings->get($key) : null;
    }
}
