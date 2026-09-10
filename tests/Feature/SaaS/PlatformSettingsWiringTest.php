<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\SaaS\Actions\Tenants\BackupTenant;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Events\TenantOnboarded;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Process\ExecutableFinder;
use Tests\TestCase;

/**
 * Every platform settings key is READ somewhere, or it is decoration. This file proves the wiring: the marketing
 * shell shows the platform's identity and banner, sign-up honours the open switch, the allowed domains and the
 * three provisioning defaults, the console honours its own idle limit, and a daily backup honours the retention.
 */
final class PlatformSettingsWiringTest extends TestCase
{
    private const SLUG = 's4-wired';

    private function settings(): PlatformSettings
    {
        return app(PlatformSettings::class);
    }

    public function test_the_marketing_shell_shows_the_platforms_identity_support_contacts_and_banner(): void
    {
        $this->settings()->set(PlatformSettingsRegistry::PLATFORM_NAME, 'Sheba Cloud');
        $this->settings()->set(PlatformSettingsRegistry::SUPPORT_EMAIL, 'help@sheba.test');
        $this->settings()->set(PlatformSettingsRegistry::SUPPORT_PHONE, '+880 1700 000000');
        $this->settings()->set(PlatformSettingsRegistry::MAINTENANCE_BANNER, 'Down for maintenance Friday 02:00–03:00.');

        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);

        foreach (['/', '/pricing', '/docs', '/changelog', '/signup'] as $url) {
            $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
                ->where('platform.name', 'Sheba Cloud')
                ->where('platform.support_email', 'help@sheba.test')
                ->where('platform.support_phone', '+880 1700 000000')
                ->where('platform.maintenance_banner', 'Down for maintenance Friday 02:00–03:00.')
                ->where('platform.signup_open', true));
        }

        // The footer key the shell renders it with exists in both languages (the site's copy is server-rendered).
        $this->assertSame('Support:', __('saas.footer.support', [], 'en'));
        $this->assertNotSame('saas.footer.support', __('saas.footer.support', [], 'bn'));
    }

    public function test_a_closed_signup_shows_the_message_and_refuses_the_form(): void
    {
        $this->settings()->set(PlatformSettingsRegistry::SIGNUP_OPEN, false);
        $this->settings()->set(PlatformSettingsRegistry::SIGNUP_CLOSED_MESSAGE, 'Private beta — ask us for an invitation.');
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);

        $this->get('/signup')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Central/Onboarding/Signup')
            ->where('platform.signup_open', false)
            ->where('platform.signup_closed_message', 'Private beta — ask us for an invitation.'));

        $this->from('/signup')->post('/signup', $this->payload())->assertRedirect('http://bp.test/signup')->assertSessionHasErrors(['clinic_name' => 'Private beta — ask us for an invitation.']);
        $this->assertNull(Tenant::query()->where('slug', self::SLUG)->first());

        // Blank message: the standard copy.
        $this->settings()->set(PlatformSettingsRegistry::SIGNUP_CLOSED_MESSAGE, '');
        $this->get('/signup')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('platform.signup_closed_message', __('saas.onboarding.closed')));
    }

    public function test_sign_up_is_limited_to_the_allowed_email_domains(): void
    {
        $this->settings()->set(PlatformSettingsRegistry::ALLOWED_EMAIL_DOMAINS, 'sheba.test, partner.example');
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);

        $this->from('/signup')->post('/signup', $this->payload(['owner_email' => 'owner@gmail.com']))->assertSessionHasErrors('owner_email');
        $this->assertNull(Tenant::query()->where('slug', self::SLUG)->first());

        // A subdomain of an allowed domain passes validation (the rest of the payload is valid, so it provisions).
        Event::fake([TenantOnboarded::class]);
        $this->post('/signup', $this->payload(['owner_email' => 'owner@clinic.partner.example']))->assertSessionDoesntHaveErrors('owner_email');
    }

    public function test_a_new_clinic_inherits_the_default_locale_timezone_and_trial_length(): void
    {
        Event::fake([TenantOnboarded::class]);
        $this->settings()->set(PlatformSettingsRegistry::DEFAULT_LOCALE, 'en');
        $this->settings()->set(PlatformSettingsRegistry::DEFAULT_TIMEZONE, 'Asia/Kolkata');
        $this->settings()->set(PlatformSettingsRegistry::TRIAL_DAYS, 21);
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);

        $this->get('/signup')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('defaults.locale', 'en')->where('defaults.timezone', 'Asia/Kolkata')->where('defaults.trial_days', 21));

        // The wizard sent neither locale nor timezone: the platform defaults fill them in.
        $this->post('/signup', $this->payload())->assertRedirect();

        $tenant = Tenant::query()->where('slug', self::SLUG)->firstOrFail();
        $this->assertSame('en', $tenant->locale->value);
        $this->assertSame('Asia/Kolkata', $tenant->timezone);
        $this->assertNotNull($tenant->trial_ends_at);
        $this->assertSame(21, (int) round(CarbonImmutable::now()->diffInDays($tenant->trial_ends_at, true)));
        $subscriptionTrial = Subscription::query()->findOrFail($tenant->current_subscription_id)->getAttribute('trial_ends_at');
        $this->assertInstanceOf(\DateTimeInterface::class, $subscriptionTrial);
        $this->assertSame(21, (int) round(CarbonImmutable::now()->diffInDays($subscriptionTrial, true)));
    }

    public function test_the_console_honours_its_own_idle_limit(): void
    {
        $this->settings()->set(PlatformSettingsRegistry::CONSOLE_IDLE_MINUTES, 5);
        $this->actingAsSuper();

        // Ten minutes idle with a five-minute limit: signed out on the next request, with the reason.
        $this->withSession([EnforceIdleTimeout::SESSION_KEY => time() - 600]);
        $this->get('/settings')->assertRedirect('http://super.bp.test/login');

        // Zero disables the idle limit for the console only.
        $this->settings()->set(PlatformSettingsRegistry::CONSOLE_IDLE_MINUTES, 0);
        $this->actingAsSuper();
        $this->withSession([EnforceIdleTimeout::SESSION_KEY => time() - 86400]);
        $this->get('/settings')->assertOk();
    }

    public function test_a_daily_backup_expires_after_the_configured_retention(): void
    {
        if ((new ExecutableFinder)->find('pg_dump') === null) {
            $this->markTestSkipped('pg_dump is not installed');
        }

        Storage::fake('backups');
        $this->settings()->set(PlatformSettingsRegistry::BACKUP_RETENTION_DAYS, 45);

        $backup = app(BackupTenant::class)->handle($this->tenant('a'), BackupType::Daily);
        $expires = $backup->getAttribute('expires_at');

        $this->assertInstanceOf(\DateTimeInterface::class, $expires);
        $this->assertSame(45, (int) round(CarbonImmutable::now()->diffInDays($expires, true)));
        $this->assertNull(app(BackupTenant::class)->handle($this->tenant('a'), BackupType::Manual)->getAttribute('expires_at'), 'manual copies are kept until removed');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return $overrides + [
            'clinic_name' => 'S4 Wired Clinic',
            'slug' => self::SLUG,
            'owner_name' => 'Wired Owner',
            'owner_email' => 'owner@sheba.test',
            'owner_mobile' => '+8801711111111',
            'password' => 'correct-horse-battery',
            'password_confirmation' => 'correct-horse-battery',
            'plan' => 'starter',
            'branch_name' => 'Main',
            'demo' => false,
        ] + $overrides;
    }
}
