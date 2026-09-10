<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\SaaS\Actions\Settings\UpdatePlatformSetting;
use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Exceptions\InvalidPlatformSettingValue;
use App\Domain\SaaS\Exceptions\UnknownPlatformSettingKey;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\PlatformSetting;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\SaaS\Concerns\ControlsPlatformSettings;
use Tests\TestCase;

/**
 * `public.platform_settings` (SCHEMA §2.19): the registry, the cached service, the audited action, and the
 * console screen that renders the registry — with the password it re-asks before touching a security key.
 */
final class PlatformSettingsTest extends TestCase
{
    use ControlsPlatformSettings;

    private const PASSWORD = 'secret-123';

    private const KEY = PlatformSettingsRegistry::SUPER_TWO_FACTOR;

    public function test_every_registry_key_has_its_copy_in_both_languages(): void
    {
        $keys = array_keys(PlatformSettingsRegistry::all());
        $this->assertContains(self::KEY, $keys);

        foreach (['en', 'bn'] as $locale) {
            $messages = json_decode((string) file_get_contents(base_path("resources/lang/{$locale}.json")), true);
            $this->assertIsArray($messages);

            foreach (PlatformSettingsRegistry::all() as $key => $definition) {
                $this->assertArrayHasKey("super.settings.{$key}.label", $messages, "{$locale}: label for {$key}");
                $this->assertArrayHasKey("super.settings.{$key}.description", $messages, "{$locale}: description for {$key}");
                $this->assertArrayHasKey('super.settings.group.'.PlatformSettingsRegistry::groupOf($key), $messages, "{$locale}: group for {$key}");

                foreach ($definition['options'] ?? [] as $option) {
                    $this->assertArrayHasKey("super.settings.{$key}.options.{$option}", $messages, "{$locale}: option {$option} of {$key}");
                    $this->assertArrayHasKey("super.settings.{$key}.options_help.{$option}", $messages, "{$locale}: option help {$option} of {$key}");
                }
            }
        }

        // The security switch re-asks the password; it is a closed list of exactly the enum's values.
        $this->assertTrue(PlatformSettingsRegistry::requiresPassword(self::KEY));
        $this->assertSame(SuperTwoFactorPolicy::values(), PlatformSettingsRegistry::definition(self::KEY)['options'] ?? null);
        $this->assertSame([PlatformSettingsRegistry::SMS_API_TOKEN, PlatformSettingsRegistry::SMS_PASSWORD], PlatformSettingsRegistry::secretKeys(), 'the platform SMS gateway credentials are the secret keys');
        $this->assertTrue(PlatformSettingsRegistry::requiresPassword(PlatformSettingsRegistry::CONSOLE_IDLE_MINUTES), 'every security.* key re-asks the password');

        // Every key is rendered by exactly one screen, and the notifications screen carries only its own groups.
        foreach (array_keys(PlatformSettingsRegistry::all()) as $key) {
            $screen = PlatformSettingsRegistry::screenOf($key);
            $this->assertContains($screen, ['settings', 'notifications'], $key);
            $this->assertSame($screen === 'notifications', in_array(PlatformSettingsRegistry::groupOf($key), ['mail', 'sms', 'templates'], true), $key);
        }
    }

    public function test_the_registry_validates_keys_and_values(): void
    {
        $this->assertSame('disabled', PlatformSettingsRegistry::validate(self::KEY, 'disabled'));

        $this->expectException(InvalidPlatformSettingValue::class);
        PlatformSettingsRegistry::validate(self::KEY, 'sometimes');
    }

    public function test_an_unknown_key_is_refused_by_the_registry_and_the_service(): void
    {
        $this->assertFalse(PlatformSettingsRegistry::has('security.nope'));

        $this->expectException(UnknownPlatformSettingKey::class);
        app(PlatformSettings::class)->set('security.nope', 'x');
    }

    public function test_the_service_reads_the_default_then_the_row_and_invalidates_its_cache_on_write(): void
    {
        $settings = app(PlatformSettings::class);
        $settings->reset(self::KEY);

        $this->assertSame(PlatformSettingsRegistry::default(self::KEY), $settings->get(self::KEY));
        $this->assertTrue(Cache::has(PlatformSettings::CACHE_KEY), 'the first read primes the cache');

        $row = $settings->set(self::KEY, 'disabled');
        $this->assertInstanceOf(PlatformSetting::class, $row);
        $this->assertFalse(Cache::has(PlatformSettings::CACHE_KEY), 'a write drops the cached document');

        // A second, fresh instance — what the next request gets — sees the new value; so does the bulk read.
        $fresh = new PlatformSettings(Cache::store());
        $this->assertSame('disabled', $fresh->get(self::KEY));
        $this->assertSame('disabled', $fresh->all()[self::KEY]);
        $this->assertTrue(Cache::has(PlatformSettings::CACHE_KEY));

        // A stale cache would keep the old value: prove the read really goes through the cache…
        Cache::put(PlatformSettings::CACHE_KEY, [self::KEY => 'optional'], 60);
        $this->assertSame('optional', $fresh->get(self::KEY));
        // …and that the service's own write path is what clears it.
        $settings->set(self::KEY, 'required');
        $this->assertSame('required', $fresh->get(self::KEY));

        $settings->reset(self::KEY);
        $this->assertSame(0, PlatformSetting::query()->where('key', self::KEY)->count());
        $this->assertSame(PlatformSettingsRegistry::default(self::KEY), $fresh->get(self::KEY));
    }

    public function test_the_action_writes_an_audit_row_with_before_and_after_and_only_when_something_changed(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Required);
        $admin = SuperAdmin::factory()->create();
        $action = app(UpdatePlatformSetting::class);

        $this->assertSame('disabled', $action->handle(self::KEY, 'disabled', $admin));

        $log = AuditLogCentral::query()->where('action', 'settings_change')->latest('id')->first();
        $this->assertInstanceOf(AuditLogCentral::class, $log);
        $this->assertSame($admin->id, $log->super_admin_id);
        $this->assertSame(PlatformSetting::class, $log->auditable_type);
        $this->assertSame(['key' => self::KEY, 'value' => 'required'], $log->before);
        $this->assertSame(['key' => self::KEY, 'value' => 'disabled'], $log->after);
        $this->assertSame($admin->id, PlatformSetting::query()->where('key', self::KEY)->value('updated_by_super_admin_id'));

        // Same value again: nothing changed, nothing recorded.
        $action->handle(self::KEY, 'disabled', $admin);
        $this->assertSame(1, AuditLogCentral::query()->where('action', 'settings_change')->count());

        // No operator — a command or a seeder — is "the system": super_admin_id NULL, as SCHEMA §2.13 wants.
        $action->handle(self::KEY, 'optional');
        $system = AuditLogCentral::query()->where('action', 'settings_change')->latest('id')->first();
        $this->assertInstanceOf(AuditLogCentral::class, $system);
        $this->assertNull($system->super_admin_id);
        $this->assertNull(PlatformSetting::query()->where('key', self::KEY)->value('updated_by_super_admin_id'));
    }

    public function test_the_screen_renders_the_registry_with_its_copy_and_the_current_value(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);
        $this->actingAsSuper();

        // The settings screen renders every `settings`-screen key grouped by first segment; `security` is the third
        // group (platform, onboarding, security, backups) and the two-factor switch is its first row. The
        // notifications-screen keys (mail, sms, templates) are not here.
        $settingsKeys = array_filter(array_keys(PlatformSettingsRegistry::all()), fn (string $k) => PlatformSettingsRegistry::screenOf($k) === 'settings');
        $securityKeys = array_values(array_filter($settingsKeys, fn (string $k) => PlatformSettingsRegistry::groupOf($k) === 'security'));
        $this->assertSame(self::KEY, $securityKeys[0]);

        $this->get('/settings')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Settings/Index')
                ->has('groups', 4)
                ->where('groups.0.key', 'platform')
                ->where('groups.1.key', 'onboarding')
                ->where('groups.2.key', 'security')
                ->where('groups.3.key', 'backups')
                ->where('groups.2.label', __('super.settings.group.security'))
                ->has('groups.2.settings', count($securityKeys))
                ->where('groups.2.settings.0.key', self::KEY)
                ->where('groups.2.settings.0.type', 'string')
                ->where('groups.2.settings.0.value', 'disabled')
                ->where('groups.2.settings.0.default', PlatformSettingsRegistry::default(self::KEY))
                ->where('groups.2.settings.0.requires_password', true)
                ->where('groups.2.settings.0.secret', false)
                ->where('groups.2.settings.0.is_set', true)
                ->where('groups.2.settings.0.label', __('super.settings.'.self::KEY.'.label'))
                ->has('groups.2.settings.0.options', 3)
                ->where('groups.2.settings.0.options.0.value', 'required')
                ->where('groups.2.settings.0.options.0.label', __('super.settings.'.self::KEY.'.options.required'))
                ->where('groups.2.settings.0.options.2.value', 'disabled')
                ->has('groups.2.settings.0.updated_at')
                ->where('groups.0.settings', fn ($rows) => collect(self::rows($rows))->pluck('key')->contains(PlatformSettingsRegistry::MAINTENANCE_BANNER)
                    && collect(self::rows($rows))->firstWhere('key', PlatformSettingsRegistry::MAINTENANCE_BANNER)['multiline'] === true
                    && collect(self::rows($rows))->firstWhere('key', PlatformSettingsRegistry::SUPPORT_EMAIL)['input'] === 'email')
                ->where('groups.1.settings', fn ($rows) => collect(self::rows($rows))->firstWhere('key', PlatformSettingsRegistry::TRIAL_DAYS)['min'] === 0
                    && collect(self::rows($rows))->firstWhere('key', PlatformSettingsRegistry::TRIAL_DAYS)['max'] === 365)
                ->where('groups', fn ($groups) => ! collect(self::rows($groups))->pluck('key')->contains('mail')));
    }

    public function test_changing_the_security_policy_needs_the_current_password_and_is_audited(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);
        $admin = $this->actingAsSuper();
        $admin->forceFill(['password' => self::PASSWORD])->save();
        $url = '/settings/'.self::KEY;

        // No password.
        $this->from('/settings')->put($url, ['value' => 'required'])->assertRedirect('http://super.bp.test/settings')->assertSessionHasErrors('password');
        $this->assertSame(SuperTwoFactorPolicy::Disabled->value, app(PlatformSettings::class)->get(self::KEY));

        // Wrong password.
        $this->from('/settings')->put($url, ['value' => 'required', 'password' => 'not-it'])->assertSessionHasErrors('password');
        $this->assertSame(SuperTwoFactorPolicy::Disabled->value, app(PlatformSettings::class)->get(self::KEY));
        $this->assertSame(0, AuditLogCentral::query()->where('action', 'settings_change')->where('super_admin_id', $admin->id)->count());

        // Right password, bad value: a domain error, nothing written.
        $this->from('/settings')->put($url, ['value' => 'sometimes', 'password' => self::PASSWORD])->assertSessionHasErrors('domain');
        $this->assertSame(SuperTwoFactorPolicy::Disabled->value, app(PlatformSettings::class)->get(self::KEY));

        // Right password, good value.
        $this->put($url, ['value' => 'required', 'password' => self::PASSWORD])
            ->assertRedirect('http://super.bp.test/settings')
            ->assertSessionHas('flash.success');

        $this->assertSame('required', app(PlatformSettings::class)->get(self::KEY));
        $log = AuditLogCentral::query()->where('action', 'settings_change')->where('super_admin_id', $admin->id)->first();
        $this->assertInstanceOf(AuditLogCentral::class, $log);
        $this->assertSame(['key' => self::KEY, 'value' => 'disabled'], $log->before);
        $this->assertSame(['key' => self::KEY, 'value' => 'required'], $log->after);

        // The screen now shows who changed it, and the next request runs under the new policy.
        $this->get('/settings')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('groups.2.settings.0.value', 'required')
                ->where('groups.2.settings.0.updated_by', $admin->name));

        // An unknown key is a domain error, not a 500.
        $this->from('/settings')->put('/settings/security.nope', ['value' => 'x'])->assertSessionHasErrors('domain');
    }

    public function test_the_screen_and_the_write_are_closed_to_guests(): void
    {
        $this->asCentral();

        $this->get('/settings')->assertRedirect('http://super.bp.test/login');
        $this->put('/settings/'.self::KEY, ['value' => 'disabled', 'password' => self::PASSWORD])->assertRedirect('http://super.bp.test/login');
    }

    public function test_the_secret_mask_shows_a_tail_and_nothing_for_an_empty_value(): void
    {
        $this->assertSame('', PlatformSettings::mask(''));
        $this->assertSame('', PlatformSettings::mask(null));
        $this->assertSame(PlatformSettings::MASK_PREFIX, PlatformSettings::mask('abcd'));
        $this->assertSame(PlatformSettings::MASK_PREFIX.'6789', PlatformSettings::mask('0123456789'));
    }

    /**
     * A page prop as AssertableInertia hands it to a `where` closure: a Collection for lists, an array otherwise.
     *
     * @return array<int|string, mixed>
     */
    private static function rows(mixed $value): array
    {
        return $value instanceof Collection ? $value->all() : (array) $value;
    }
}
