<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Services\SmsOtpSender;
use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Services\OtpService;
use App\Domain\Prescription\Actions\RequestDelivery;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Events\PdfReady;
use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Prescription\Services\VerificationCode;
use App\Domain\Shared\Actor;
use App\Http\Resources\Notifications\NotificationResource;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Prescription;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\Request;
use Tests\Feature\Notifications\Concerns\NotificationFixtures;
use Tests\Feature\Notifications\Concerns\ScriptedDriver;
use Tests\TestCase;

/**
 * The two seams this module was asked to close: the Patients module's OtpSender (which logged instead of sending)
 * and the Prescription module's delivery request (which recorded an attempt as if it were a delivery).
 */
final class OtpAndPrescriptionTest extends TestCase
{
    use NotificationFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Clock::freeze('2026-03-12 10:00');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    // ---- OTP ------------------------------------------------------------------------------------------------

    public function test_the_real_sms_backed_otp_sender_is_bound(): void
    {
        $this->assertInstanceOf(SmsOtpSender::class, app(OtpSender::class));
    }

    public function test_requesting_an_otp_writes_a_notification_and_sends_it(): void
    {
        $recorder = $this->recordingDriver();
        $patient = $this->patient(['name' => 'রহিমা খাতুন']);

        app(OtpService::class)->request($patient->mobile, OtpPurpose::Login);

        $rows = $this->notifications(NotificationEvent::Otp->value);
        $this->assertCount(1, $rows);
        $this->assertSame(NotificationStatus::Sent, $rows[0]->status);
        $this->assertSame($patient->id, $rows[0]->patient_id);
        $this->assertSame($patient->mobile, $rows[0]->recipient);
        $this->assertSame('login', $rows[0]->payload['purpose']);

        // OTP_FIXED_CODE=000000 in the test environment (phpunit.xml)
        $this->assertStringContainsString('000000', $recorder->sent[0]['body']);
        $this->assertStringContainsString('যাচাই কোড', $recorder->sent[0]['body'], 'a Bangla patient gets a Bangla code message');
    }

    public function test_an_otp_for_a_mobile_with_no_patient_record_is_still_delivered(): void
    {
        $this->recordingDriver();

        app(OtpService::class)->request('01799999999', OtpPurpose::Booking);

        $rows = $this->notifications(NotificationEvent::Otp->value);
        $this->assertCount(1, $rows);
        $this->assertNull($rows[0]->patient_id);
        $this->assertSame('+8801799999999', $rows[0]->recipient);
    }

    /** The code is a live credential: it must never be readable from the outbound log screen. */
    public function test_the_outbound_log_redacts_an_otp_body(): void
    {
        $this->recordingDriver();
        app(OtpService::class)->request('01799999999', OtpPurpose::Login);

        $row = Notification::query()->where('event_key', NotificationEvent::Otp->value)->firstOrFail();
        $payload = (new NotificationResource($row))->toArray(Request::create('/'));

        $this->assertNull($payload['body']);
        $this->assertTrue($payload['body_redacted']);
        $this->assertSame('017*****999', $payload['recipient']);
    }

    public function test_a_non_otp_body_is_shown_in_the_log(): void
    {
        $row = Notification::factory()->create(['body' => 'Serial A-012 confirmed.']);
        $payload = (new NotificationResource($row))->toArray(Request::create('/'));

        $this->assertSame('Serial A-012 confirmed.', $payload['body']);
        $this->assertFalse($payload['body_redacted']);
    }

    // ---- prescription ---------------------------------------------------------------------------------------

    private function issuedPrescription(): Prescription
    {
        $patient = $this->patient(['email' => 'patient@example.test']);
        $doctor = $this->queueDoctor('dr-rx');

        return Prescription::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'status' => PrescriptionStatus::Issued,
            'issued_at' => now(),
            'snapshot' => ['prescription' => ['mode' => 'structured']],
            'snapshot_sha256' => str_repeat('a', 64),
            'verification_code' => VerificationCode::generate(),
            'pdf_path' => 'tenants/9001/pdfs/existing.pdf',
        ]);
    }

    public function test_issuing_a_prescription_notifies_the_patient_with_the_verification_link(): void
    {
        $this->recordingDriver();
        $rx = $this->issuedPrescription();

        event(new PrescriptionIssued(
            tenantId: (int) Tenancy::id(),
            prescriptionId: $rx->id,
            visitId: (int) $rx->visit_id,
            patientId: (int) $rx->patient_id,
            doctorId: (int) $rx->doctor_id,
            serialId: null,
            prescriptionPublicId: $rx->public_id,
            version: 1,
            verificationCode: (string) $rx->verification_code,
        ));

        $rows = $this->notifications(NotificationEvent::PrescriptionReady->value);
        $this->assertCount(1, $rows);
        $this->assertStringContainsString('/rx/'.$rx->verification_code, $rows[0]->body);
        $this->assertSame('prescription_ready:'.$rx->id.':sms', $rows[0]->dedupe_key);
    }

    /** P3's caveat closed: the column now means "a gateway accepted it", not "somebody clicked send". */
    public function test_delivered_channels_records_gateway_success_not_the_request(): void
    {
        $this->recordingDriver();
        $rx = $this->issuedPrescription();

        $this->assertSame([], $rx->delivered_channels);

        app(RequestDelivery::class)->handle($rx, 'sms', null, Actor::user(1, 'receptionist'));

        $this->assertSame(['sms'], $rx->refresh()->delivered_channels);
        $this->assertSame(NotificationStatus::Sent, Notification::query()->where('event_key', NotificationEvent::PrescriptionReady->value)->firstOrFail()->status);
    }

    public function test_a_rejected_delivery_leaves_delivered_channels_empty(): void
    {
        $this->bindDriver(new ScriptedDriver([DeliveryResult::rejected('INVALID_NUMBER')]));
        $rx = $this->issuedPrescription();

        app(RequestDelivery::class)->handle($rx, 'sms', null, Actor::user(1, 'receptionist'));

        $this->assertSame([], $rx->refresh()->delivered_channels, 'a refused SMS is not a delivery');
        $this->assertSame(NotificationStatus::Failed, Notification::query()->where('event_key', NotificationEvent::PrescriptionReady->value)->firstOrFail()->status);
    }

    public function test_a_manual_delivery_may_be_repeated_to_a_corrected_number(): void
    {
        $this->recordingDriver();
        $rx = $this->issuedPrescription();
        $actor = Actor::user(1, 'receptionist');

        app(RequestDelivery::class)->handle($rx, 'sms', '01711111111', $actor);
        app(RequestDelivery::class)->handle($rx->refresh(), 'sms', '01722222222', $actor);

        $rows = $this->notifications(NotificationEvent::PrescriptionReady->value);
        $this->assertCount(2, $rows, 'a receptionist correcting a wrong number must be able to resend');
        $this->assertSame(['+8801711111111', '+8801722222222'], array_map(fn (Notification $n) => $n->recipient, $rows));
    }

    /** An email whose PDF is still rendering is parked, then released the moment PdfReady lands. */
    public function test_an_email_delivery_waits_for_the_pdf_and_is_released_by_pdf_ready(): void
    {
        $this->recordingDriver();
        $rx = $this->issuedPrescription();
        $rx->forceFill(['pdf_path' => null])->save();

        app(RequestDelivery::class)->handle($rx, 'email', null, Actor::user(1, 'receptionist'));

        $row = Notification::query()->where('event_key', NotificationEvent::PrescriptionReady->value)->firstOrFail();
        $this->assertSame(NotificationStatus::Scheduled, $row->status);
        $this->assertNull($row->payload['pdf_path']);

        event(new PdfReady($rx->id, 'tenants/9001/pdfs/rx.pdf', $rx->public_id));

        $row->refresh();
        $this->assertSame(NotificationStatus::Sent, $row->status);
        $this->assertSame('tenants/9001/pdfs/rx.pdf', $row->payload['pdf_path']);
    }

    public function test_an_sms_delivery_never_waits_for_a_pdf(): void
    {
        $this->recordingDriver();
        $rx = $this->issuedPrescription();
        $rx->forceFill(['pdf_path' => null])->save();

        app(RequestDelivery::class)->handle($rx, 'sms', null, Actor::user(1, 'receptionist'));

        $row = Notification::query()->where('event_key', NotificationEvent::PrescriptionReady->value)->firstOrFail();
        $this->assertSame(NotificationStatus::Sent, $row->status, 'the verification URL works without a rendered PDF');
    }
}
