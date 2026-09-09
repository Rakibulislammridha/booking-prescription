<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Support\SettingsRegistry;
use App\Domain\Patients\Services\OcrSettings;
use App\Domain\Telemedicine\Services\TelemedicineSettings;
use App\Models\Tenant\Setting;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * `secret` registry keys (SCHEMA Appendix B, SettingsRegistry).
 *
 * The bug this closes: the generic settings screen renders every registry key from its definition, so
 * `telemedicine.api_secret` was a plain text input writing a video API secret into `settings.value` — plain
 * `jsonb`, in every backup, readable with a psql prompt — while the telemedicine module's own writer encrypted
 * it. Two writers, one column, one of them wrong. `secret` moves the encryption into the service both go through.
 */
final class SettingsSecretTest extends TestCase
{
    private const SECRET = 'lk-api-secret-6f2c9b';

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
    }

    public function test_every_credential_in_the_registry_is_marked_secret(): void
    {
        // The sweep, pinned: a credential added to the registry without the flag fails here rather than shipping
        // as a plain text field. If this list changes, the reason should be in the commit message.
        $this->assertSame(
            ['telemedicine.api_key', 'telemedicine.api_secret', 'patients.ocr_api_key'],
            SettingsRegistry::secretKeys(),
        );

        foreach (SettingsRegistry::secretKeys() as $key) {
            $this->assertSame('string', SettingsRegistry::definition($key)['type']);
            $this->assertTrue(SettingsRegistry::isSecret($key));
        }

        $this->assertFalse(SettingsRegistry::isSecret('telemedicine.host'));
        $this->assertFalse(SettingsRegistry::isSecret('queue.notify_ahead'));
    }

    public function test_a_secret_round_trips_but_never_sits_in_the_row_in_clear_text(): void
    {
        $settings = app(Settings::class);
        $settings->set('telemedicine.api_secret', self::SECRET);

        $this->assertSame(self::SECRET, $settings->get('telemedicine.api_secret'));

        $stored = (string) DB::table('settings')->where('key', 'telemedicine.api_secret')->value('value');
        $this->assertStringNotContainsString(self::SECRET, $stored);
        $this->assertSame(self::SECRET, Crypt::decryptString(trim($stored, '"')));
    }

    /** The module writers encrypt for themselves and predate this; the service must not wrap the value twice. */
    public function test_a_value_that_is_already_encrypted_is_stored_as_is(): void
    {
        app(TelemedicineSettings::class)->storeSecret(self::SECRET);
        app(OcrSettings::class)->storeApiKey('ocr-key-1234');

        $this->assertSame(self::SECRET, app(Settings::class)->get('telemedicine.api_secret'));
        $this->assertSame(self::SECRET, app(TelemedicineSettings::class)->credentials()->apiSecret);
        $this->assertSame('ocr-key-1234', app(OcrSettings::class)->apiKey());

        $stored = (string) DB::table('settings')->where('key', 'telemedicine.api_secret')->value('value');
        $this->assertSame(self::SECRET, Crypt::decryptString(trim($stored, '"')), 'the value was encrypted twice');
    }

    public function test_the_screen_receives_a_mask_and_never_the_plaintext(): void
    {
        app(Settings::class)->set('telemedicine.api_secret', self::SECRET);
        app(Settings::class)->set('patients.ocr_api_key', 'ocr-key-9999');

        $response = $this->get('/panel/clinic/settings', $this->inertiaHeaders())->assertOk();
        $body = $response->getContent();
        $this->assertIsString($body);
        $this->assertStringNotContainsString(self::SECRET, $body, 'the plaintext credential reached the client');
        $this->assertStringNotContainsString('ocr-key-9999', $body);

        $props = (array) $response->json('props');
        $values = (array) $props['values'];
        $registry = (array) $props['registry'];

        $this->assertSame('••••2c9b', $values['telemedicine.api_secret']);
        $this->assertSame('••••9999', $values['patients.ocr_api_key']);
        $this->assertSame('', $values['telemedicine.api_key'], 'nothing stored reads as nothing set');
        $this->assertTrue($registry['telemedicine.api_secret']['secret']);
        $this->assertFalse($registry['telemedicine.host']['secret']);

        // The bulk read is masked everywhere, not just on this screen.
        $this->assertSame('••••2c9b', app(Settings::class)->all()['telemedicine.api_secret']);
    }

    public function test_a_blank_submit_keeps_the_stored_credential(): void
    {
        app(Settings::class)->set('telemedicine.api_secret', self::SECRET);

        // An untouched password box. Laravel's ConvertEmptyStringsToNull turns '' into null before the request is
        // read, so BOTH shapes must mean "unchanged" — if either one deleted, an ordinary save of some other field
        // on the same screen would silently wipe the clinic's video credentials.
        foreach (['', null] as $blank) {
            $this->put('/panel/clinic/settings', ['values' => [
                'telemedicine.api_secret' => $blank,
                'telemedicine.host' => 'meet.clinic.test',
            ]])->assertRedirect()->assertSessionHasNoErrors();

            $this->assertSame(self::SECRET, app(Settings::class)->get('telemedicine.api_secret'), 'a blank submit wiped the credential');
        }

        $this->assertSame('meet.clinic.test', app(Settings::class)->get('telemedicine.host'));

        $this->put('/panel/clinic/settings', ['values' => ['telemedicine.api_secret' => 'replaced-abcd']])->assertRedirect();
        $this->assertSame('replaced-abcd', app(Settings::class)->get('telemedicine.api_secret'));
    }

    public function test_removing_a_credential_takes_its_own_explicit_list(): void
    {
        app(Settings::class)->set('telemedicine.api_secret', self::SECRET);
        $row = Setting::query()->where('key', 'telemedicine.api_secret')->firstOrFail();

        $this->put('/panel/clinic/settings', ['values' => [], 'remove' => ['telemedicine.api_secret']])->assertRedirect();

        $this->assertSame('', app(Settings::class)->get('telemedicine.api_secret'));
        $this->assertSame(0, Setting::query()->where('key', 'telemedicine.api_secret')->count());

        $log = $this->assertAudited(AuditAction::Delete, $row, ['key' => 'telemedicine.api_secret']);
        $this->assertSame(['value' => '[redacted]'], $log->before);
    }

    public function test_a_payload_that_both_writes_and_removes_the_same_key_writes(): void
    {
        $this->put('/panel/clinic/settings', [
            'values' => ['telemedicine.api_secret' => 'kept-value-1234'],
            'remove' => ['telemedicine.api_secret'],
        ])->assertRedirect();

        $this->assertSame('kept-value-1234', app(Settings::class)->get('telemedicine.api_secret'));
    }

    public function test_the_audit_row_shows_the_redaction_rather_than_the_secret(): void
    {
        $this->put('/panel/clinic/settings', ['values' => [
            'telemedicine.api_secret' => self::SECRET,
            'queue.notify_ahead' => 4,
        ]])->assertRedirect();

        $secretRow = Setting::query()->where('key', 'telemedicine.api_secret')->firstOrFail();
        $log = $this->assertAudited(AuditAction::Update, $secretRow, ['key' => 'telemedicine.api_secret']);

        $this->assertSame(['value' => '[redacted]'], $log->before);
        $this->assertSame(['value' => '[redacted]'], $log->after);

        // Not the ciphertext either: an audit log is read by more people, kept longer and exported.
        $encoded = (string) json_encode([$log->before, $log->after]);
        $this->assertStringNotContainsString(self::SECRET, $encoded);
        $this->assertStringNotContainsString('eyJpdiI6', $encoded);

        // An ordinary key still records what it actually changed to.
        $plainRow = Setting::query()->where('key', 'queue.notify_ahead')->firstOrFail();
        $this->assertSame(['value' => 4], $this->assertAudited(AuditAction::Update, $plainRow)->after);
    }

    public function test_a_blank_submit_on_a_secret_writes_no_audit_row_at_all(): void
    {
        app(Settings::class)->set('telemedicine.api_secret', self::SECRET);
        $row = Setting::query()->where('key', 'telemedicine.api_secret')->firstOrFail();

        $this->put('/panel/clinic/settings', ['values' => ['telemedicine.api_secret' => '']])->assertRedirect();

        $this->assertNotAudited(AuditAction::Update, $row);
    }
}
