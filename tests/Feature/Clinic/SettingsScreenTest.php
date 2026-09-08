<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * BRIEF §5.A settings + SCHEMA Appendix B. The page is generated from SettingsRegistry, so the test asserts the
 * registry reaches the page intact, that saving type-checks against it, and that the per-tenant cache is dropped.
 */
final class SettingsScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
    }

    public function test_the_page_is_generated_from_the_registry(): void
    {
        $this->get('/panel/clinic/settings')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Settings/Index')
            ->has('registry', count(SettingsRegistry::all()))
            ->has('values', count(SettingsRegistry::all()))
            ->has('groups', 10)         // + telemedicine (Module K) + patients (§5.H OCR naming)
            ->where('groups.0.prefix', 'queue')
            ->where('branding.name', $this->tenant()->name)
            ->has('branding.timezone')
            ->where('can.manage', true));

        // Registry keys are dotted, which every dot-path helper would read as nesting — index the props directly.
        $props = (array) $this->get('/panel/clinic/settings', $this->inertiaHeaders())->assertOk()->json('props');
        $registry = (array) $props['registry'];
        $this->assertSame('int', $registry['queue.auto_noshow_after']['type']);
        $this->assertSame(3, $registry['queue.auto_noshow_after']['default']);
        $this->assertSame(30, $registry['serial.default_block_size']['max']);
        $this->assertSame(['both', 'bn', 'en', 'off'], $registry['queue.display_voice']['options']);
        $this->assertSame(3, ((array) $props['values'])['queue.auto_noshow_after']);
    }

    public function test_saving_a_group_persists_typed_values_and_drops_the_tenant_cache(): void
    {
        $settings = app(Settings::class);
        $this->assertSame(3, $settings->get('queue.auto_noshow_after'));
        $this->assertSame(3, Cache::get(Settings::cacheKeyFor(9001))['queue.auto_noshow_after'] ?? 3, 'warm the cache');

        $this->put('/panel/clinic/settings', ['values' => [
            'queue.auto_noshow_after' => 5,
            'queue.display_voice' => 'bn',
            'serial.expected_show_rate' => 0.6,
            'serial.vip_enabled' => false,
        ]])->assertRedirect();

        $this->assertSame(5, Setting::query()->where('key', 'queue.auto_noshow_after')->value('value'));
        $this->assertSame('bn', app(Settings::class)->get('queue.display_voice'));
        $this->assertSame(0.6, app(Settings::class)->get('serial.expected_show_rate'));
        $this->assertFalse(app(Settings::class)->get('serial.vip_enabled'));
        $this->assertNotNull(Setting::query()->where('key', 'queue.auto_noshow_after')->value('updated_by_user_id'));

        $props = (array) $this->get('/panel/clinic/settings', $this->inertiaHeaders())->json('props');
        $this->assertSame(5, ((array) $props['values'])['queue.auto_noshow_after']);
    }

    public function test_the_field_rules_come_from_the_registry(): void
    {
        $this->put('/panel/clinic/settings', ['values' => ['queue.auto_noshow_after' => 'three']])
            ->assertSessionHasErrors('values.queue.auto_noshow_after');

        $this->put('/panel/clinic/settings', ['values' => ['serial.default_block_size' => 31]])
            ->assertSessionHasErrors('values.serial.default_block_size');

        $this->put('/panel/clinic/settings', ['values' => ['serial.expected_show_rate' => 1.5]])
            ->assertSessionHasErrors('values.serial.expected_show_rate');

        $this->put('/panel/clinic/settings', ['values' => ['queue.display_voice' => 'fr']])
            ->assertSessionHasErrors('values.queue.display_voice');

        $this->assertSame(0, Setting::query()->count(), 'nothing is written when the payload is rejected');
    }

    public function test_an_unknown_key_is_ignored_rather_than_written(): void
    {
        $this->put('/panel/clinic/settings', ['values' => ['nope.key' => 1, 'queue.notify_ahead' => 4]])->assertRedirect();

        $this->assertSame(4, app(Settings::class)->get('queue.notify_ahead'));
        $this->assertSame(['queue.notify_ahead'], Setting::query()->pluck('key')->all());
    }

    public function test_branding_writes_the_central_row_and_stores_the_logo_under_the_tenant_prefix(): void
    {
        Storage::fake('public');

        $this->put('/panel/clinic/branding', [
            'name' => 'সেবা হাসপাতাল', 'name_bn' => 'সেবা হাসপাতাল', 'locale' => 'bn',
            'primary_color' => '#112233', 'accent_color' => '#abcdef', 'on_primary_color' => '#ffffff',
            'logo' => UploadedFile::fake()->image('logo.png', 300, 120),
        ])->assertRedirect();

        $tenant = $this->tenant()->fresh();
        $this->assertSame('সেবা হাসপাতাল', $tenant->name);
        $this->assertSame('bn', $tenant->locale->value);
        $this->assertSame('#112233', $tenant->branding['primary_color']);
        $this->assertStringStartsWith('tenants/9001/branding/logo-', (string) $tenant->branding['logo_path']);
        Storage::disk('public')->assertExists((string) $tenant->branding['logo_path']);

        // The colours the public site themes with come straight off this row (SharedProps.tenant.theme).
        $this->get('/panel/clinic/settings')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('tenant.theme.primary', '#112233')
            ->where('tenant.theme.accent', '#abcdef')
            ->where('branding.primary_color', '#112233'));
    }

    public function test_branding_rejects_a_colour_that_is_not_hex(): void
    {
        $this->put('/panel/clinic/branding', ['name' => 'Clinic', 'locale' => 'bn', 'primary_color' => 'red'])
            ->assertSessionHasErrors('primary_color');
    }

    public function test_a_receptionist_can_read_the_settings_but_not_save_them(): void
    {
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel/clinic/settings')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can.manage', false));

        $this->put('/panel/clinic/settings', ['values' => ['queue.notify_ahead' => 9]])->assertForbidden();
        $this->put('/panel/clinic/branding', ['name' => 'Nope', 'locale' => 'bn'])->assertForbidden();
    }
}
