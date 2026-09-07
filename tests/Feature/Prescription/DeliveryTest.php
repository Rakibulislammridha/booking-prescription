<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Prescription\Events\PrescriptionDeliveryRequested;
use App\Domain\Prescription\Jobs\GeneratePrescriptionPdf;
use App\Domain\Prescription\Services\PdfStorage;
use App\Domain\Prescription\Services\VerificationUrl;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * PRESCRIPTION.md §7.7 — sending. This module raises the event with everything a gateway needs and records the
 * attempt; templates, SMS/WhatsApp/email transports and retry live in Notifications (ARCHITECTURE §5.4).
 */
final class DeliveryTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_sending_raises_the_event_with_the_verification_link_and_audits(): void
    {
        Event::fake([PrescriptionDeliveryRequested::class]);
        Queue::fake();
        [$rx] = $this->issuedWithContent();

        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/send', ['channel' => 'sms'])
            ->assertStatus(202)
            ->assertJsonPath('queued', true)
            ->assertJsonPath('channel', 'sms')
            ->assertJsonPath('pdf_status', 'pending');

        Event::assertDispatched(PrescriptionDeliveryRequested::class, function (PrescriptionDeliveryRequested $event) use ($rx) {
            return $event->prescriptionId === $rx->id
                && $event->channel === 'sms'
                && $event->to === $rx->patient->mobile                       // default recipient = the patient
                && $event->verificationUrl === VerificationUrl::for((string) $rx->verification_code)
                && $event->pdfPath === null                                   // Notifications chains after PdfReady
                && $event->language === $rx->language->value;
        });

        // The PDF is not there yet, so sending asks for it — otherwise the chained listener waits on nothing.
        Queue::assertPushed(GeneratePrescriptionPdf::class, fn (GeneratePrescriptionPdf $job) => $job->prescriptionId === $rx->id);

        // `delivered_channels` is no longer written here. The Notifications module owns it and appends the channel
        // on gateway SUCCESS (App\Domain\Notifications\Listeners\RecordPrescriptionDelivery), so the issued view
        // shows what was actually accepted rather than what somebody clicked. With the event faked, nothing sends.
        $this->assertSame([], $rx->fresh()?->delivered_channels);
        $this->assertAudited(AuditAction::Share, $rx, ['event' => 'sent', 'channel' => 'sms']);
    }

    public function test_the_recipient_is_masked_in_the_audit_trail(): void
    {
        Event::fake([PrescriptionDeliveryRequested::class]);
        Queue::fake();
        [$rx] = $this->issuedWithContent();

        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/send', ['channel' => 'email', 'to' => 'rehana@example.com'])->assertStatus(202);

        $log = $this->assertAudited(AuditAction::Share, $rx, ['event' => 'sent', 'channel' => 'email']);
        $this->assertSame('r***@example.com', $log->context['to_masked']);
        $this->assertStringNotContainsString('rehana@example.com', json_encode($log->context) ?: '');
    }

    public function test_an_existing_pdf_is_carried_in_the_payload(): void
    {
        Storage::fake('pdfs');
        Event::fake([PrescriptionDeliveryRequested::class]);
        Queue::fake();
        [$rx] = $this->issuedWithContent();

        $path = app(PdfStorage::class)->put($rx, '%PDF-1.4 fake');
        $rx->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();

        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/send', ['channel' => 'whatsapp'])->assertStatus(202)->assertJsonPath('pdf_status', 'ready');
        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/send', ['channel' => 'whatsapp'])->assertStatus(202);

        Event::assertDispatched(PrescriptionDeliveryRequested::class, fn (PrescriptionDeliveryRequested $e) => $e->pdfPath === $path);
        Queue::assertNotPushed(GeneratePrescriptionPdf::class);
        $this->assertSame([], $rx->fresh()?->delivered_channels, 'the column now records gateway success, which a faked event never reaches');
    }

    public function test_a_draft_cannot_be_sent(): void
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);

        $this->postJson('/panel/prescriptions/'.$draft->public_id.'/send', ['channel' => 'sms'])
            ->assertStatus(409)
            ->assertJsonPath('code', 'prescriptions.not_latest_version');
    }
}
