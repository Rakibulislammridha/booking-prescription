<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Notifications\Actions\QueueNotification;
use App\Domain\Notifications\Data\NotificationRequest;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Services\QuietHours;
use App\Domain\Patients\Enums\ConsentType;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Patient;
use App\Support\Clock;
use Illuminate\Database\UniqueConstraintViolationException;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\TestCase;

/**
 * Dedupe, consent and quiet hours — the three reasons a message legitimately does not go out, and the one reason
 * (dedupe) that must hold under concurrency.
 */
final class SuppressionTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->recordingDriver();
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function request(Patient $patient, NotificationEvent $event = NotificationEvent::BookingConfirmed, ?string $dedupeKey = 'k:1'): NotificationRequest
    {
        return new NotificationRequest(
            event: $event,
            channel: NotificationChannel::Sms,
            patient: $patient,
            variables: ['clinic' => 'সেবা হাসপাতাল', 'serial' => 'A-012', 'doctor' => 'ডা. রহমান', 'date' => '১২ মার্চ', 'time' => 'সকাল ১০:০০', 'branch' => 'প্রধান'],
            dedupeKey: $dedupeKey,
        );
    }

    // ---- dedupe ---------------------------------------------------------------------------------------------

    public function test_the_same_dedupe_key_writes_one_row(): void
    {
        $patient = $this->patient();
        $queue = app(QueueNotification::class);

        $first = $queue->handle($this->request($patient));
        $second = $queue->handle($this->request($patient));

        $this->assertCount(1, $first);
        $this->assertSame([], $second);
        $this->assertSame(1, Notification::query()->count());
    }

    public function test_the_dedupe_key_is_scoped_per_channel_so_a_two_channel_fan_out_is_not_swallowed(): void
    {
        config(['notifications.event_channels' => ['booking_confirmed' => ['sms', 'email']]]);
        $patient = $this->patient(['email' => 'patient@example.test']);
        $queue = app(QueueNotification::class);

        $queue->handle($this->request($patient));
        $queue->handle($this->request($patient)->withChannel(NotificationChannel::Email));

        $this->assertSame(2, Notification::query()->count());
        $this->assertSame(['k:1:sms', 'k:1:email'], Notification::query()->orderBy('id')->pluck('dedupe_key')->all());
    }

    public function test_a_request_without_a_dedupe_key_may_repeat(): void
    {
        $patient = $this->patient();
        $queue = app(QueueNotification::class);

        $queue->handle($this->request($patient, dedupeKey: null));
        $queue->handle($this->request($patient, dedupeKey: null));

        $this->assertSame(2, Notification::query()->count(), 'a manual resend is a deliberate act and is not deduped');
    }

    public function test_the_database_refuses_a_duplicate_dedupe_key_even_if_the_check_is_bypassed(): void
    {
        $patient = $this->patient();
        app(QueueNotification::class)->handle($this->request($patient));

        $this->expectException(UniqueConstraintViolationException::class);

        Notification::query()->create([
            'event_key' => NotificationEvent::BookingConfirmed,
            'channel' => NotificationChannel::Sms,
            'patient_id' => $patient->id,
            'recipient' => $patient->mobile,
            'locale' => 'bn',
            'body' => 'duplicate',
            'status' => NotificationStatus::Queued,
            'dedupe_key' => 'k:1:sms',
        ]);
    }

    // ---- consent --------------------------------------------------------------------------------------------

    public function test_a_revoked_sms_consent_suppresses_the_message(): void
    {
        $patient = $this->patient();
        $this->revokeConsent($patient, ConsentType::Sms);

        $this->assertSame([], app(QueueNotification::class)->handle($this->request($patient)));
        $this->assertSame(0, Notification::query()->count());
    }

    /** `patient_consents` is append-only: the LATEST row wins, so a re-granted consent restores delivery. */
    public function test_a_granted_consent_after_a_revocation_allows_the_message_again(): void
    {
        Clock::freeze('2026-03-12 10:00');
        $patient = $this->patient();
        $this->revokeConsent($patient, ConsentType::Sms);

        Clock::freeze('2026-03-12 11:00');
        $this->grantConsent($patient, ConsentType::Sms);

        $this->assertCount(1, app(QueueNotification::class)->handle($this->request($patient)));
    }

    public function test_whatsapp_is_gated_on_its_own_consent_type(): void
    {
        config(['notifications.event_channels' => ['booking_confirmed' => ['whatsapp']]]);
        $patient = $this->patient();
        $this->revokeConsent($patient, ConsentType::Whatsapp);

        $this->assertSame([], app(QueueNotification::class)->handle($this->request($patient)->withChannel(NotificationChannel::Whatsapp)));
        $this->assertCount(1, app(QueueNotification::class)->handle($this->request($patient)), 'SMS is unaffected by a WhatsApp revocation');
    }

    public function test_an_otp_is_never_suppressed_by_consent(): void
    {
        $patient = $this->patient();
        $this->revokeConsent($patient, ConsentType::Sms);

        $rows = app(QueueNotification::class)->handle(new NotificationRequest(
            event: NotificationEvent::Otp,
            channel: NotificationChannel::Sms,
            patient: $patient,
            variables: ['clinic' => 'X', 'code' => '123456', 'minutes' => '5'],
        ));

        $this->assertCount(1, $rows, 'a patient who just asked for a login code must receive it');
    }

    public function test_opt_in_mode_requires_an_explicit_consent_row(): void
    {
        config(['notifications.consent.require_explicit' => true]);
        $patient = $this->patient();

        $this->assertSame([], app(QueueNotification::class)->handle($this->request($patient)));

        $this->grantConsent($patient, ConsentType::Sms);
        $this->assertCount(1, app(QueueNotification::class)->handle($this->request($patient, dedupeKey: 'k:2')));
    }

    // ---- quiet hours ----------------------------------------------------------------------------------------

    /** Quiet hours are a tenant setting (SCHEMA Appendix B) and default to OFF; a clinic turns them on. */
    private function quietHoursOn(): void
    {
        app(Settings::class)->set('notifications.quiet_hours_enabled', true);
    }

    public function test_a_non_urgent_message_produced_at_night_is_scheduled_for_the_morning(): void
    {
        $this->quietHoursOn();
        Clock::freeze('2026-03-12 23:10');       // clinic-local Asia/Dhaka
        $patient = $this->patient();

        $rows = app(QueueNotification::class)->handle($this->request($patient));

        $this->assertCount(1, $rows);
        $this->assertSame(NotificationStatus::Scheduled, $rows[0]->status, 'held, never dropped');
        $this->assertSame('2026-03-13 08:00', $rows[0]->scheduled_for?->setTimezone('Asia/Dhaka')->format('Y-m-d H:i'), 'the registry default window is 21:00–08:00');
    }

    public function test_an_urgent_message_ignores_quiet_hours(): void
    {
        $this->quietHoursOn();
        Clock::freeze('2026-03-12 23:10');
        $patient = $this->patient();

        $rows = app(QueueNotification::class)->handle($this->request($patient, NotificationEvent::DoctorCancelled));

        $this->assertSame(NotificationStatus::Sent, $rows[0]->refresh()->status, 'a cancelled clinic is worse withheld than delivered at 23:10');
    }

    public function test_a_message_produced_inside_working_hours_is_sent_at_once(): void
    {
        Clock::freeze('2026-03-12 10:00');

        $rows = app(QueueNotification::class)->handle($this->request($this->patient()));

        $this->assertSame(NotificationStatus::Sent, $rows[0]->refresh()->status);
    }

    public function test_the_quiet_window_wraps_past_midnight_in_the_clinic_timezone(): void
    {
        $this->quietHoursOn();
        $quiet = app(QuietHours::class);

        Clock::freeze('2026-03-12 22:30');
        $this->assertTrue($quiet->isQuiet());

        Clock::freeze('2026-03-13 02:00');
        $this->assertTrue($quiet->isQuiet());

        Clock::freeze('2026-03-13 07:59');
        $this->assertTrue($quiet->isQuiet());

        Clock::freeze('2026-03-13 08:00');
        $this->assertFalse($quiet->isQuiet());
    }

    /** The window itself is a tenant setting too, not a constant in the module. */
    public function test_the_clinic_chooses_its_own_window(): void
    {
        $this->quietHoursOn();
        app(Settings::class)->set('notifications.quiet_hours_start', '23:00');
        app(Settings::class)->set('notifications.quiet_hours_end', '06:00');

        Clock::freeze('2026-03-12 22:30');
        $this->assertFalse(app(QuietHours::class)->isQuiet());

        Clock::freeze('2026-03-12 23:30');
        $this->assertTrue(app(QuietHours::class)->isQuiet());
    }

    public function test_quiet_hours_are_off_until_the_clinic_turns_them_on(): void
    {
        Clock::freeze('2026-03-12 23:10');

        $this->assertFalse(app(QuietHours::class)->isQuiet(), 'BRIEF §5.J: respected only when the settings define them');
        $this->assertSame(NotificationStatus::Sent, app(QueueNotification::class)->handle($this->request($this->patient()))[0]->refresh()->status);
    }

    // ---- no recipient ---------------------------------------------------------------------------------------

    public function test_a_patient_without_an_email_produces_no_email_row(): void
    {
        config(['notifications.event_channels' => ['booking_confirmed' => ['email']]]);
        $patient = $this->patient(['email' => null]);

        $this->assertSame([], app(QueueNotification::class)->handle($this->request($patient)->withChannel(NotificationChannel::Email)));
    }

    // ---- booking confirmation through the real producer -----------------------------------------------------

    public function test_booking_an_appointment_confirms_it_to_the_patient_once(): void
    {
        Clock::freeze('2026-03-12 10:00');
        $session = $this->queueSession($this->queueDoctor(), 'A', counter: 30);

        app(BookAppointment::class)->handle(
            new BookingRequest(channel: BookingChannel::Counter, mobile: '01712345678', name: 'রহিমা খাতুন', ageYears: 40, sessionPublicId: $session->public_id),
            Actor::user(1, 'receptionist'),
        );

        $rows = $this->notifications(NotificationEvent::BookingConfirmed->value);
        $this->assertCount(1, $rows);
        $this->assertSame('+8801712345678', $rows[0]->recipient);
        $this->assertStringContainsString('A-001', $rows[0]->body);
        $this->assertStringStartsWith('booking_confirmed:', (string) $rows[0]->dedupe_key);
        $this->assertNotNull($rows[0]->segments, 'the SMS segment count is stored for billing reconciliation');
    }
}
