<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Exceptions\InvalidSettingValue;
use App\Domain\Clinic\Exceptions\UnknownSettingKey;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class SettingsServiceTest extends TestCase
{
    public function test_defaults_come_from_the_registry_when_no_row_exists(): void
    {
        $this->asTenant('a');
        $settings = app(Settings::class);

        $this->assertSame(3, $settings->get('queue.auto_noshow_after'));
        $this->assertSame(0.8, $settings->get('serial.expected_show_rate'));
        $this->assertTrue($settings->get('kiosk.otp_required'));
        $this->assertSame(SettingsRegistry::defaults(), $settings->all());
        $this->assertSame(['serial.'], array_unique(array_map(fn (string $k) => substr($k, 0, 7), array_keys($settings->withPrefix('serial.')))));
    }

    public function test_set_type_checks_persists_and_invalidates_the_tenant_cache(): void
    {
        $this->asTenant('a');
        $settings = app(Settings::class);
        $user = User::factory()->create();

        $settings->set('queue.auto_noshow_after', '5', $user);

        $this->assertSame(5, $settings->get('queue.auto_noshow_after'));
        $this->assertSame(5, Setting::query()->where('key', 'queue.auto_noshow_after')->value('value'));
        $this->assertSame($user->id, Setting::query()->where('key', 'queue.auto_noshow_after')->value('updated_by_user_id'));
        $this->assertSame(5, Cache::get(Settings::cacheKeyFor(9001))['queue.auto_noshow_after'] ?? null);

        $settings->reset('queue.auto_noshow_after');
        $this->assertSame(3, $settings->get('queue.auto_noshow_after'));
    }

    public function test_unknown_keys_and_wrong_types_are_domain_errors(): void
    {
        $this->asTenant('a');
        $settings = app(Settings::class);

        $this->assertThrows(fn () => $settings->get('nope.key'), UnknownSettingKey::class);
        $this->assertThrows(fn () => $settings->set('nope.key', 1), UnknownSettingKey::class);
        $this->assertThrows(fn () => $settings->set('queue.auto_noshow_after', 'three'), InvalidSettingValue::class);
        $this->assertThrows(fn () => $settings->set('serial.default_block_size', 31), InvalidSettingValue::class);
        $this->assertThrows(fn () => $settings->set('queue.display_voice', 'fr'), InvalidSettingValue::class);
        $this->assertThrows(fn () => $settings->set('serial.expected_show_rate', 1.5), InvalidSettingValue::class);
    }

    public function test_settings_are_cached_per_tenant_and_isolated(): void
    {
        $this->asTenant('a');
        app(Settings::class)->set('serial.vip_enabled', false);

        $this->asTenant('b');
        $this->assertTrue(app(Settings::class)->get('serial.vip_enabled'));

        $this->asTenant('a');
        $this->assertFalse(app(Settings::class)->get('serial.vip_enabled'));
    }

    public function test_settings_require_tenancy(): void
    {
        $this->expectException(TenancyNotInitialized::class);
        app(Settings::class)->get('serial.vip_enabled');
    }
}
