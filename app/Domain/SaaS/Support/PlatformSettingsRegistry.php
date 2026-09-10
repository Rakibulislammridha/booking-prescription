<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\SaaS\Actions\Tenants\BackupTenant;
use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Exceptions\InvalidPlatformSettingValue;
use App\Domain\SaaS\Exceptions\UnknownPlatformSettingKey;

/**
 * The closed registry of PLATFORM settings keys — `public.platform_settings` (SCHEMA §2.19), the control plane's
 * counterpart of the tenant registry (`App\Domain\Clinic\Support\SettingsRegistry`, SCHEMA Appendix B). One row
 * per key, a missing row means the default, and the default may be derived from config so that a deploy-time
 * env value seeds the console toggle rather than competing with it.
 *
 * The same storage contracts as the tenant registry, read by the same downstream code paths:
 *
 *   · `secret` — a credential: `PlatformSettings::set()` encrypts it, `all()` masks it, a blank submit keeps it,
 *     and the audit row says `[redacted]`. The platform SMS gateway's token and password are the first ones.
 *   · `reauth` — changing the key re-asks the operator's CURRENT PASSWORD (not a TOTP code: the first such key
 *     is the switch that turns the second factor off, and a switch that needs the thing it disables is not a
 *     switch). Every `security.*` key carries it.
 *   · `screen` — which console screen renders the key: `settings` (default, the Platform settings page) or
 *     `notifications` (the platform's outgoing identity, SMS defaults and mail templates, which belong next to
 *     the test-send button and the outbound log rather than in a list of switches).
 *   · `multiline` / `input` — control hints for the screen (a textarea; an email/tel/url box); they change nothing
 *     about storage or validation, which is `pattern`, `options`, `min`/`max`, `max_length` and `timezone`.
 *
 * Every key is READ somewhere — a setting nobody reads is decoration, and the registry says where in a comment:
 * the marketing shell, the sign-up wizard, provisioning, the idle-timeout middleware, backup pruning, the platform
 * mailer, the tenant gateway resolver. The label, description and option copy live under `super.settings.<key>.*`
 * in both language files (a test asserts they exist).
 *
 * @phpstan-type SettingDefinition array{type: string, default: mixed, options?: array<int, string>, pattern?: string, min?: int|float, max?: int|float, max_length?: int, timezone?: bool, secret?: bool, reauth?: bool, screen?: string, multiline?: bool, input?: string, placeholders?: array<int, string>}
 */
final class PlatformSettingsRegistry
{
    public const SUPER_TWO_FACTOR = 'security.super_two_factor';

    public const PLATFORM_NAME = 'platform.name';

    public const SUPPORT_EMAIL = 'platform.support_email';

    public const SUPPORT_PHONE = 'platform.support_phone';

    public const MAINTENANCE_BANNER = 'platform.maintenance_banner';

    public const SIGNUP_OPEN = 'onboarding.signup_open';

    public const SIGNUP_CLOSED_MESSAGE = 'onboarding.signup_closed_message';

    public const DEFAULT_LOCALE = 'onboarding.default_locale';

    public const DEFAULT_TIMEZONE = 'onboarding.default_timezone';

    public const TRIAL_DAYS = 'onboarding.trial_days';

    public const ALLOWED_EMAIL_DOMAINS = 'onboarding.allowed_email_domains';

    public const CONSOLE_IDLE_MINUTES = 'security.console_idle_minutes';

    public const BACKUP_RETENTION_DAYS = 'backups.retention_days';

    public const MAIL_FROM_NAME = 'mail.from_name';

    public const MAIL_FROM_ADDRESS = 'mail.from_address';

    public const MAIL_REPLY_TO = 'mail.reply_to';

    public const SMS_PROVIDER = 'sms.provider';

    public const SMS_SENDER_ID = 'sms.sender_id';

    public const SMS_URL = 'sms.url';

    public const SMS_API_TOKEN = 'sms.api_token';

    public const SMS_SID = 'sms.sid';

    public const SMS_USERNAME = 'sms.username';

    public const SMS_PASSWORD = 'sms.password';

    /** `sms.provider` value meaning "the platform offers no SMS gateway of its own". */
    public const SMS_PROVIDER_NONE = 'none';

    /** The platform → owner mails whose subject and body an operator may rewrite (PlatformMailer reads them). */
    public const TEMPLATES = ['welcome', 'dunning', 'dunning_final', 'suspended'];

    /** @var array<string, array<int, string>> template → the `:placeholders` its listener supplies */
    public const TEMPLATE_PLACEHOLDERS = [
        'welcome' => ['clinic', 'owner', 'url', 'trial_ends'],
        'dunning' => ['clinic', 'number', 'amount', 'due', 'suspends_on', 'step'],
        'dunning_final' => ['clinic', 'number', 'amount', 'due', 'suspends_on', 'step'],
        'suspended' => ['clinic', 'number'],
    ];

    private const EMAIL_PATTERN = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';

    private const OPTIONAL_EMAIL_PATTERN = '/^([^\s@]+@[^\s@]+\.[^\s@]+)?$/';

    private const PHONE_PATTERN = '/^[+0-9 ()\-]{0,32}$/';

    private const OPTIONAL_URL_PATTERN = '~^(https?://\S+)?$~';

    private const DOMAIN_LIST_PATTERN = '/^(\s*[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}\s*([,\s]+[a-z0-9][a-z0-9.\-]*\.[a-z]{2,}\s*)*)?$/i';

    /** @return array<string, SettingDefinition> */
    public static function all(): array
    {
        return [
            // ── Platform identity — read by the central marketing shell (BuildsCentralLinks::platformProps()),
            // the onboarding "done" page and the platform mailer's default From name. ────────────────────────
            self::PLATFORM_NAME => ['type' => 'string', 'default' => (string) config('app.name', 'Booking to Prescription'), 'max_length' => 80],
            self::SUPPORT_EMAIL => ['type' => 'string', 'default' => (string) config('mail.from.address', ''), 'pattern' => self::EMAIL_PATTERN, 'input' => 'email', 'max_length' => 255],
            self::SUPPORT_PHONE => ['type' => 'string', 'default' => '', 'pattern' => self::PHONE_PATTERN, 'input' => 'tel', 'max_length' => 32],
            self::MAINTENANCE_BANNER => ['type' => 'string', 'default' => '', 'multiline' => true, 'max_length' => 500],

            // ── Onboarding — read by OnboardingController / SignUpRequest (the wizard's defaults, the closed
            // switch, the allowed owner-email domains) and by SignUpTenant → ProvisionTenant (trial length). ──
            self::SIGNUP_OPEN => ['type' => 'bool', 'default' => true],
            self::SIGNUP_CLOSED_MESSAGE => ['type' => 'string', 'default' => '', 'multiline' => true, 'max_length' => 500],
            self::DEFAULT_LOCALE => ['type' => 'string', 'default' => 'bn', 'options' => ['bn', 'en']],
            self::DEFAULT_TIMEZONE => ['type' => 'string', 'default' => 'Asia/Dhaka', 'timezone' => true, 'max_length' => 64],
            self::TRIAL_DAYS => ['type' => 'int', 'default' => 14, 'min' => 0, 'max' => 365],
            self::ALLOWED_EMAIL_DOMAINS => ['type' => 'string', 'default' => '', 'pattern' => self::DOMAIN_LIST_PATTERN, 'max_length' => 1000],

            // ── Security. The second-factor policy (ARCHITECTURE §6.5, SuperTwoFactorPolicy): the default is
            // seeded from `saas.two_factor.required` (env SUPER_2FA_REQUIRED) — `true` is `required`, the
            // production default; `false` is `optional`, the old meaning of the env flag — so an existing
            // deployment keeps its behaviour until an operator chooses otherwise in the console. The idle limit
            // is read by EnforceIdleTimeout for guard `super` (0 = no idle limit on the console). ──────────────
            self::SUPER_TWO_FACTOR => [
                'type' => 'string',
                'default' => config('saas.two_factor.required', true) ? SuperTwoFactorPolicy::Required->value : SuperTwoFactorPolicy::Optional->value,
                'options' => SuperTwoFactorPolicy::values(),
                'reauth' => true,
            ],
            self::CONSOLE_IDLE_MINUTES => [
                'type' => 'int',
                'default' => max(0, (int) config('session.idle_timeout_minutes', (int) config('session.lifetime', 120))),
                'min' => 0, 'max' => 1440, 'reauth' => true,
            ],

            // ── Backups — read by BackupTenant when it stamps `expires_at` on a daily copy; `tenants:backup
            // --prune` deletes what has expired. ──────────────────────────────────────────────────────────────
            self::BACKUP_RETENTION_DAYS => ['type' => 'int', 'default' => BackupTenant::DAILY_RETENTION_DAYS, 'min' => 1, 'max' => 365],

            // ── Platform notifications screen: the outgoing email identity (PlatformMailer, and the tenant email
            // driver through NotificationsServiceProvider), the SMS gateway tenants inherit when they have no row
            // of their own (PlatformSmsGateway → GatewayResolver), and the platform → owner mail templates. ────
            self::MAIL_FROM_NAME => ['type' => 'string', 'default' => (string) config('mail.from.name', ''), 'screen' => 'notifications', 'max_length' => 120],
            self::MAIL_FROM_ADDRESS => ['type' => 'string', 'default' => (string) config('mail.from.address', ''), 'pattern' => self::EMAIL_PATTERN, 'input' => 'email', 'screen' => 'notifications', 'max_length' => 255],
            self::MAIL_REPLY_TO => ['type' => 'string', 'default' => '', 'pattern' => self::OPTIONAL_EMAIL_PATTERN, 'input' => 'email', 'screen' => 'notifications', 'max_length' => 255],

            self::SMS_PROVIDER => ['type' => 'string', 'default' => self::SMS_PROVIDER_NONE, 'options' => [self::SMS_PROVIDER_NONE, ...GatewayProvider::values()], 'screen' => 'notifications'],
            self::SMS_SENDER_ID => ['type' => 'string', 'default' => '', 'screen' => 'notifications', 'max_length' => 32],
            self::SMS_URL => ['type' => 'string', 'default' => '', 'pattern' => self::OPTIONAL_URL_PATTERN, 'input' => 'url', 'screen' => 'notifications', 'max_length' => 500],
            self::SMS_API_TOKEN => ['type' => 'string', 'default' => '', 'secret' => true, 'screen' => 'notifications', 'max_length' => 500],
            self::SMS_SID => ['type' => 'string', 'default' => '', 'screen' => 'notifications', 'max_length' => 120],
            self::SMS_USERNAME => ['type' => 'string', 'default' => '', 'screen' => 'notifications', 'max_length' => 120],
            self::SMS_PASSWORD => ['type' => 'string', 'default' => '', 'secret' => true, 'screen' => 'notifications', 'max_length' => 500],

            ...self::templateDefinitions(),
        ];
    }

    /** `templates.<name>.<subject|body>.<locale>` — defaults are the shipped `saas.mail.*` strings in that locale. */
    public static function templateKey(string $template, string $field, string $locale): string
    {
        return "templates.{$template}.{$field}.{$locale}";
    }

    /** @return array<string, SettingDefinition> */
    private static function templateDefinitions(): array
    {
        $out = [];

        foreach (self::TEMPLATES as $template) {
            foreach (['subject', 'body'] as $field) {
                foreach (['en', 'bn'] as $locale) {
                    $out[self::templateKey($template, $field, $locale)] = [
                        'type' => 'string',
                        'default' => (string) __("saas.mail.{$template}.{$field}", [], $locale),
                        'screen' => 'notifications',
                        'multiline' => $field === 'body',
                        'max_length' => $field === 'body' ? 4000 : 200,
                        'placeholders' => self::TEMPLATE_PLACEHOLDERS[$template],
                    ];
                }
            }
        }

        return $out;
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, self::all());
    }

    /** A credential: encrypted at rest, masked on the wire, redacted in audit rows. */
    public static function isSecret(string $key): bool
    {
        return (self::all()[$key]['secret'] ?? false) === true;
    }

    /** Changing this key re-asks the operator's current password. */
    public static function requiresPassword(string $key): bool
    {
        return (self::all()[$key]['reauth'] ?? false) === true;
    }

    /** Which console screen renders the key: `settings` or `notifications`. */
    public static function screenOf(string $key): string
    {
        return (string) (self::definition($key)['screen'] ?? 'settings');
    }

    /** @return array<int, string> every secret key, for a sweep or an assertion */
    public static function secretKeys(): array
    {
        return array_keys(array_filter(self::all(), fn (array $d) => ($d['secret'] ?? false) === true));
    }

    /** @return array<string, mixed> */
    public static function defaults(): array
    {
        return array_map(fn (array $def) => $def['default'], self::all());
    }

    public static function default(string $key): mixed
    {
        return self::definition($key)['default'];
    }

    /** The first dotted segment (`security`), which is how the screen groups keys. */
    public static function groupOf(string $key): string
    {
        return explode('.', $key, 2)[0];
    }

    /** @return SettingDefinition */
    public static function definition(string $key): array
    {
        return self::all()[$key] ?? throw new UnknownPlatformSettingKey($key);
    }

    /** Type-checks and normalises a value for the key; throws InvalidPlatformSettingValue. */
    public static function validate(string $key, mixed $value): mixed
    {
        $def = self::definition($key);

        // A blank credential field is "unchanged", not a type error (ConvertEmptyStringsToNull makes it null).
        if ($value === null && ($def['secret'] ?? false) === true) {
            return null;
        }

        $ok = match ($def['type']) {
            'int' => is_int($value) || (is_string($value) && preg_match('/^-?\d+$/', $value) === 1),
            'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
            'bool' => is_bool($value) || in_array($value, [0, 1, '0', '1', 'true', 'false'], true),
            'string' => is_string($value),
            default => false,
        };

        if (! $ok) {
            throw new InvalidPlatformSettingValue($key, "expected {$def['type']}");
        }

        $value = match ($def['type']) {
            'int' => (int) $value,
            'number' => is_string($value) ? (float) $value : $value,
            'bool' => is_bool($value) ? $value : in_array($value, [1, '1', 'true'], true),
            default => trim((string) $value),
        };

        if (isset($def['options']) && ! in_array($value, $def['options'], true)) {
            throw new InvalidPlatformSettingValue($key, 'must be one of '.implode(', ', $def['options']));
        }

        if (isset($def['pattern']) && (! is_string($value) || preg_match($def['pattern'], $value) !== 1)) {
            throw new InvalidPlatformSettingValue($key, 'must match '.$def['pattern']);
        }

        if (isset($def['max_length']) && is_string($value) && mb_strlen($value) > $def['max_length']) {
            throw new InvalidPlatformSettingValue($key, "must be at most {$def['max_length']} characters");
        }

        if (($def['timezone'] ?? false) === true && (! is_string($value) || ! in_array($value, timezone_identifiers_list(), true))) {
            throw new InvalidPlatformSettingValue($key, 'must be a valid timezone identifier');
        }

        if (isset($def['min']) && $value < $def['min']) {
            throw new InvalidPlatformSettingValue($key, "must be >= {$def['min']}");
        }

        if (isset($def['max']) && $value > $def['max']) {
            throw new InvalidPlatformSettingValue($key, "must be <= {$def['max']}");
        }

        return $value;
    }
}
