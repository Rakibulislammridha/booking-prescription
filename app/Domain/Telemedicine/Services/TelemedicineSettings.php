<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Telemedicine\Enums\TelemedicineProvider;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Per-tenant video credentials with config fallbacks (BRIEF §5.K, CONVENTIONS §11: tenant-editable settings are
 * rows, platform defaults are config).
 *
 * The six keys live in the closed registry of SCHEMA Appendix B under the `telemedicine.` prefix. The one secret
 * among them is stored as a Laravel-encrypted string, because `settings.value` is plain jsonb and an API secret
 * has no business sitting there in clear text — `secret()` decrypts transparently and tolerates a plain value so
 * a developer pasting a key by hand is not met with a stack trace.
 *
 * A clinic that has configured nothing gets `config('telemedicine.*')`, and a platform that has configured
 * nothing either gets the null driver — never a half-configured call to a real service.
 */
final class TelemedicineSettings
{
    public const KEY_PROVIDER = 'telemedicine.provider';

    public const KEY_HOST = 'telemedicine.host';

    public const KEY_API_KEY = 'telemedicine.api_key';

    public const KEY_API_SECRET = 'telemedicine.api_secret';

    public const KEY_RECORDING = 'telemedicine.recording_enabled';

    public const KEY_MAX_MINUTES = 'telemedicine.max_minutes';

    public function __construct(private readonly Settings $settings) {}

    public function credentials(): ProviderCredentials
    {
        $provider = $this->provider();
        $config = (array) config('telemedicine.providers.'.$provider->value, []);

        return new ProviderCredentials(
            provider: $provider,
            host: $this->string(self::KEY_HOST, (string) ($config['host'] ?? '')),
            apiKey: $this->string(self::KEY_API_KEY, (string) ($config['key'] ?? '')),
            apiSecret: $this->secret((string) ($config['secret'] ?? '')),
            recording: $this->bool(self::KEY_RECORDING, (bool) config('telemedicine.room.recording', false)),
            maxMinutes: $this->int(self::KEY_MAX_MINUTES, (int) config('telemedicine.room.max_minutes', 45)),
            tokenTtlSeconds: (int) config('telemedicine.token_ttl', 900),
        );
    }

    /** The configured provider name, or the platform default. `null` in config means "use the null driver". */
    public function driverName(): string
    {
        $tenant = $this->raw(self::KEY_PROVIDER);
        $value = is_string($tenant) && $tenant !== '' && $tenant !== 'default' ? $tenant : (string) config('telemedicine.default', 'null');

        return in_array($value, ['livekit', 'jitsi', 'agora', 'null'], true) ? $value : 'null';
    }

    /** The value written to `telemedicine_rooms.provider`; the null driver stands in for `jitsi` (see NullVideoProvider). */
    public function provider(): TelemedicineProvider
    {
        return TelemedicineProvider::tryFrom($this->driverName())
            ?? TelemedicineProvider::from((string) config('telemedicine.null_driver_records_as', 'jitsi'));
    }

    private function raw(string $key): mixed
    {
        return Tenancy::check() ? $this->settings->get($key) : null;
    }

    private function string(string $key, string $fallback): string
    {
        $value = $this->raw($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : $fallback;
    }

    private function bool(string $key, bool $fallback): bool
    {
        $value = $this->raw($key);

        return is_bool($value) ? $value : $fallback;
    }

    private function int(string $key, int $fallback): int
    {
        $value = $this->raw($key);

        return is_int($value) && $value > 0 ? $value : $fallback;
    }

    /** Tenant secret (encrypted at rest) if set, else the platform's. */
    private function secret(string $fallback): string
    {
        $stored = $this->raw(self::KEY_API_SECRET);

        if (! is_string($stored) || trim($stored) === '') {
            return $fallback;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            return trim($stored);       // written by hand, never encrypted: usable, and the panel re-encrypts on save
        }
    }

    /** What the settings screen writes: the secret never reaches the row in clear text. */
    public function storeSecret(string $plain): void
    {
        $this->settings->set(self::KEY_API_SECRET, $plain === '' ? '' : Crypt::encryptString($plain));
    }
}
