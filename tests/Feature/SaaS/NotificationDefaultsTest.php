<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Drivers\LogChannelDriver;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\SaaS\Services\PlatformMailer;
use App\Domain\SaaS\Services\PlatformMailTemplates;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\PlatformMessage;
use App\Models\Central\PlatformSetting;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

/**
 * Platform notification defaults (super.notifications.*): the outgoing identity, the SMS gateway a clinic
 * inherits, the owner mail templates with preview and reset, the test send through the drivers, and the outbound
 * ledger — with secrets masked on the wire and redacted in audit rows.
 */
final class NotificationDefaultsTest extends TestCase
{
    private function settings(): PlatformSettings
    {
        return app(PlatformSettings::class);
    }

    public function test_the_screen_renders_identity_gateway_templates_and_the_log(): void
    {
        $this->actingAsSuper();

        $this->get('/notifications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Super/Notifications/Index')
            ->has('identity', 3)->where('identity.0.key', PlatformSettingsRegistry::MAIL_FROM_NAME)
            ->where('effective_identity.address', config('mail.from.address'))
            ->has('sms', 7)->where('sms_summary.configured', false)
            ->where('templates.dunning.en.subject.value', __('saas.mail.dunning.subject', [], 'en'))
            ->where('templates.dunning.bn.body.value', __('saas.mail.dunning.body', [], 'bn'))
            ->where('templates.welcome.en.body.placeholders', ['clinic', 'owner', 'url', 'trial_ends'])
            ->where('placeholders.dunning', PlatformSettingsRegistry::TEMPLATE_PLACEHOLDERS['dunning'])
            ->has('log')->has('log_meta.total'));
    }

    public function test_identity_and_gateway_round_trip_with_masked_secrets_and_redacted_audit_rows(): void
    {
        $admin = $this->actingAsSuper();

        $this->put('/notifications/settings', ['values' => [
            PlatformSettingsRegistry::MAIL_FROM_NAME => 'Sheba Platform',
            PlatformSettingsRegistry::MAIL_FROM_ADDRESS => 'noreply@sheba.test',
            PlatformSettingsRegistry::SMS_PROVIDER => 'ssl_wireless',
            PlatformSettingsRegistry::SMS_SENDER_ID => 'SHEBA',
            PlatformSettingsRegistry::SMS_API_TOKEN => 'tok-secret-9876',
            PlatformSettingsRegistry::SMS_SID => 'SHEBASID',
        ]])->assertRedirect('http://super.bp.test/notifications')->assertSessionHas('flash.success');

        $this->assertSame('Sheba Platform', $this->settings()->get(PlatformSettingsRegistry::MAIL_FROM_NAME));
        $this->assertSame('tok-secret-9876', $this->settings()->get(PlatformSettingsRegistry::SMS_API_TOKEN), 'the accessor decrypts');
        $this->assertSame(PlatformSettings::MASK_PREFIX.'9876', $this->settings()->all()[PlatformSettingsRegistry::SMS_API_TOKEN], 'the bulk read masks');
        $this->assertNotSame('tok-secret-9876', PlatformSetting::query()->where('key', PlatformSettingsRegistry::SMS_API_TOKEN)->value('value'), 'encrypted at rest');

        $secretLog = AuditLogCentral::query()->where('action', 'settings_change')->whereRaw("after->>'key' = ?", [PlatformSettingsRegistry::SMS_API_TOKEN])->first();
        $this->assertInstanceOf(AuditLogCentral::class, $secretLog);
        $this->assertSame('[redacted]', $secretLog->after['value']);
        $this->assertSame('[redacted]', $secretLog->before['value']);
        $this->assertSame($admin->id, $secretLog->super_admin_id);
        $this->assertSame('Sheba Platform', AuditLogCentral::query()->where('action', 'settings_change')->whereRaw("after->>'key' = ?", [PlatformSettingsRegistry::MAIL_FROM_NAME])->value('after')['value'] ?? null);

        $this->get('/notifications')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('effective_identity.name', 'Sheba Platform')->where('effective_identity.address', 'noreply@sheba.test')
            ->where('sms_summary.configured', true)->where('sms_summary.provider', 'ssl_wireless')
            ->where('sms', fn ($rows) => collect(self::rows($rows))->firstWhere('key', PlatformSettingsRegistry::SMS_API_TOKEN)['value'] === PlatformSettings::MASK_PREFIX.'9876'
                && collect(self::rows($rows))->firstWhere('key', PlatformSettingsRegistry::SMS_API_TOKEN)['is_set'] === true));

        // A blank secret means "keep"; a key from the Platform settings screen is refused here.
        $this->put('/notifications/settings', ['values' => [PlatformSettingsRegistry::SMS_API_TOKEN => '', PlatformSettingsRegistry::SMS_SENDER_ID => 'SHEBA2']])->assertRedirect();
        $this->assertSame('tok-secret-9876', $this->settings()->get(PlatformSettingsRegistry::SMS_API_TOKEN));
        $this->assertSame('SHEBA2', $this->settings()->get(PlatformSettingsRegistry::SMS_SENDER_ID));
        $this->from('/notifications')->put('/notifications/settings', ['values' => [PlatformSettingsRegistry::SUPER_TWO_FACTOR => 'disabled']])->assertSessionHasErrors('values');
        $this->from('/notifications')->put('/notifications/settings', ['values' => [PlatformSettingsRegistry::MAIL_FROM_ADDRESS => 'not-an-address']])->assertSessionHasErrors('domain');

        // A clinic with no gateway row of its own inherits the platform's.
        $this->asTenant('a');
        $config = app(DriverFactory::class)->configFor(NotificationChannel::Sms);
        $this->assertInstanceOf(GatewayConfig::class, $config);
        $this->assertSame(GatewayProvider::SslWireless, $config->provider);
        $this->assertSame('tok-secret-9876', $config->credential('api_token'));
        $this->assertSame('SHEBASID', $config->credential('sid'));
        $this->assertSame('SHEBA2', $config->senderId);
        $this->assertNull($config->gatewayId, 'not a tenant row');
        $this->assertSame('ssl_wireless', app(DriverFactory::class)->for(NotificationChannel::Sms)->provider());

        // Platform mail carries the identity and lands in the ledger.
        $tenant = $this->tenant('a');
        $this->assertTrue(app(PlatformMailer::class)->toOwner($tenant, 'saas.mail.welcome.subject', 'saas.mail.welcome.body', ['owner' => 'Owner', 'url' => 'https://x', 'trial_ends' => 'soon']));
        $sent = $this->lastMail();
        $this->assertSame('noreply@sheba.test', $sent->getFrom()[0]->getAddress());
        $this->assertSame('Sheba Platform', $sent->getFrom()[0]->getName());
        $ledger = PlatformMessage::query()->where('kind', 'welcome')->where('tenant_id', $tenant->id)->latest('id')->first();
        $this->assertInstanceOf(PlatformMessage::class, $ledger);
        $this->assertSame('sent', $ledger->status);
        $this->assertSame($tenant->locale->value, $ledger->locale);
    }

    public function test_templates_can_be_edited_previewed_and_reset_to_the_shipped_text(): void
    {
        $this->actingAsSuper();
        $key = PlatformSettingsRegistry::templateKey('dunning', 'subject', 'en');

        $this->put('/notifications/settings', ['values' => [$key => 'Pay :number now, :clinic']])->assertRedirect();
        $templates = app(PlatformMailTemplates::class);
        $this->assertSame('Pay INV-1 now, Sheba', $templates->render('saas.mail.dunning.subject', ['number' => 'INV-1', 'clinic' => 'Sheba'], 'en'));
        $this->assertSame(__('saas.mail.dunning.subject', ['number' => 'INV-1'], 'bn'), $templates->render('saas.mail.dunning.subject', ['number' => 'INV-1', 'clinic' => 'Sheba'], 'bn'), 'the other locale is untouched');
        $this->assertSame(__('saas.mail.receipt.subject', [], 'en'), $templates->render('saas.mail.receipt.subject', [], 'en'), 'a mail without an editable template renders the language file');

        $this->postJson('/notifications/templates/preview', ['template' => 'dunning', 'locale' => 'en', 'subject' => 'Hi :clinic — :number', 'body' => 'Due :due'])->assertOk()
            ->assertJsonPath('subject', 'Hi Sheba Hospital — INV-2026-000123')->assertJsonPath('body', 'Due '.now()->subDays(3)->toFormattedDateString());
        $this->postJson('/notifications/templates/preview', ['template' => 'nope', 'locale' => 'en'])->assertStatus(422);

        $this->delete('/notifications/templates/'.$key)->assertRedirect('http://super.bp.test/notifications')->assertSessionHas('flash.success');
        $this->assertSame(__('saas.mail.dunning.subject', ['number' => 'INV-1'], 'en'), $templates->render('saas.mail.dunning.subject', ['number' => 'INV-1', 'clinic' => 'Sheba'], 'en'));
        $reset = AuditLogCentral::query()->where('action', 'settings_change')->whereRaw("after->>'key' = ?", [$key])->latest('id')->first();
        $this->assertInstanceOf(AuditLogCentral::class, $reset);
        $this->assertTrue($reset->after['reset']);
        $this->assertSame('Pay :number now, :clinic', $reset->before['value']);

        $this->delete('/notifications/templates/'.PlatformSettingsRegistry::SUPER_TWO_FACTOR)->assertNotFound();
    }

    public function test_a_test_send_goes_through_the_real_drivers_and_is_logged_and_audited(): void
    {
        $admin = $this->actingAsSuper();

        // Email: the array mailer receives it, the ledger and the audit log record it, the recipient is masked.
        $this->post('/notifications/test', ['channel' => 'email', 'message' => 'Hello from the console'])->assertRedirect('http://super.bp.test/notifications')->assertSessionHas('flash.success');
        $mail = $this->lastMail();
        $this->assertSame($admin->email, $mail->getTo()[0]->getAddress());
        $this->assertStringContainsString('Hello from the console', (string) $mail->getHtmlBody());
        $row = PlatformMessage::query()->where('kind', 'test')->where('channel', 'email')->latest('id')->first();
        $this->assertInstanceOf(PlatformMessage::class, $row);
        $this->assertSame('sent', $row->status);
        $this->assertSame($admin->id, $row->sent_by_super_admin_id);
        $this->assertStringNotContainsString($admin->email, $row->recipient);
        $this->assertSame(1, AuditLogCentral::query()->where('action', 'create')->whereRaw("after->>'test_message' = 'true'")->count());

        // SMS without a platform gateway is a domain error, not a silent nothing.
        $this->from('/notifications')->post('/notifications/test', ['channel' => 'sms', 'recipient' => '01712345678'])->assertSessionHasErrors('domain');
        $this->from('/notifications')->post('/notifications/test', ['channel' => 'sms', 'recipient' => 'nope'])->assertSessionHasErrors('recipient');

        // With one, the driver built from the platform config sends it — faked here so no gateway is hit.
        $this->settings()->set(PlatformSettingsRegistry::SMS_PROVIDER, 'ssl_wireless');
        $this->settings()->set(PlatformSettingsRegistry::SMS_API_TOKEN, 'tok-1234');
        $this->settings()->set(PlatformSettingsRegistry::SMS_SID, 'SID');
        $real = app(DriverFactory::class);
        $fakeFactory = new class($real) implements DriverFactory
        {
            public ?GatewayConfig $built = null;

            public ?LogChannelDriver $driver = null;

            public function __construct(private readonly DriverFactory $real) {}

            public function for(NotificationChannel $channel): ChannelDriver
            {
                return $this->real->for($channel);
            }

            public function fromConfig(GatewayConfig $config): ChannelDriver
            {
                $this->built = $config;

                return $this->driver = new LogChannelDriver($config->channel, false);
            }

            public function configFor(NotificationChannel $channel): ?GatewayConfig
            {
                return $this->real->configFor($channel);
            }
        };
        $this->app->instance(DriverFactory::class, $fakeFactory);

        $this->post('/notifications/test', ['channel' => 'sms', 'recipient' => '01712345678'])->assertRedirect()->assertSessionHas('flash.success');
        $this->assertSame('tok-1234', $fakeFactory->built?->credential('api_token'));
        $sms = PlatformMessage::query()->where('kind', 'test')->where('channel', 'sms')->latest('id')->first();
        $this->assertInstanceOf(PlatformMessage::class, $sms);
        $this->assertSame('sent', $sms->status);
        $this->assertSame('log', $sms->provider);
        $this->assertStringContainsString('*', $sms->recipient, 'the mobile number is masked');
        $this->assertStringNotContainsString('12345678', $sms->recipient, 'the mobile number is masked');
        $this->assertSame('+8801712345678', $fakeFactory->driver?->sent[0]['recipient'] ?? null, 'the driver received the E.164 number');

        $this->get('/notifications?channel=sms')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('log', 1)->where('log.0.channel', 'sms')->where('log_filters.channel', 'sms'));
        $this->get('/notifications?kind=test')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->has('log', 2));
    }

    public function test_the_screen_and_its_writes_are_closed_to_guests(): void
    {
        $this->asCentral();
        $this->get('/notifications')->assertRedirect('http://super.bp.test/login');
        $this->put('/notifications/settings', ['values' => [PlatformSettingsRegistry::MAIL_FROM_NAME => 'x']])->assertRedirect('http://super.bp.test/login');
        $this->post('/notifications/test', ['channel' => 'email'])->assertRedirect('http://super.bp.test/login');
        $this->assertSame(0, PlatformMessage::query()->where('kind', 'test')->count());
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

    /** The last message the suite's array transport received (MAIL_MAILER=array in phpunit.xml). */
    private function lastMail(): Email
    {
        $transport = app('mailer')->getSymfonyTransport();
        $this->assertInstanceOf(ArrayTransport::class, $transport);
        $sent = $transport->messages()->last();
        $this->assertInstanceOf(SentMessage::class, $sent);
        $message = $sent->getOriginalMessage();
        $this->assertInstanceOf(Email::class, $message);

        return $message;
    }
}
