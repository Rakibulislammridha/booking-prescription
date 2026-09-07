<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialAllocated;
use App\Domain\Serials\Events\SerialStatusChanged;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/** OFFLINE §12.2 OfflineIssueEndToEndTest: lease → replay register + issue + check_in + collect_cash + print. */
final class OfflineIssueEndToEndTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_offline_issue_replays_through_the_engine_with_events_audit_and_isolation(): void
    {
        Event::fake([SerialAllocated::class, SerialStatusChanged::class, AppointmentBooked::class]);
        $device = $this->device();
        $actor = $this->receptionist();
        $session = $this->openSession(30, 5, 5);
        $lease = $this->asDevice($device, $actor)->postJson(route('api.reception.blocks.lease', [], false), ['session' => $session->public_id, 'size' => 10])->assertCreated()->json('block');

        $register = $this->registerEvent(1, 'L1', '01710000101', 'রহিমা বেগম', 'f', 54);
        $issue = $this->issueEvent(2, $session, SerialBlock::query()->where('public_id', $lease['public_id'])->firstOrFail(), 1, 'local:L1', $register['client_event_id'], type: 'new', priority: 'elderly');
        $checkIn = $this->checkInEvent(3, 'local:'.$issue['client_event_id'], $issue['client_event_id']);
        $cash = $this->cashEvent(4, 'local:'.$issue['client_event_id'], 80000, 'D'.$device->number.'-000001', $issue['client_event_id']);
        $print = $this->printEvent(5, 'local:'.$issue['client_event_id'], $issue['client_event_id']);

        $json = $this->sync($device, $actor, [$register, $issue, $checkIn, $cash, $print])->assertOk()->json();
        $this->assertSame(['accepted', 'accepted', 'accepted', 'accepted', 'accepted'], array_column($json['results'], 'status'));

        $patient = Patient::query()->where('mobile', '+8801710000101')->firstOrFail();
        $this->assertSame('রহিমা বেগম', $patient->name);
        $this->assertSame($patient->public_id, $json['results'][0]['server_result']['patient']['public_id']);

        $serial = Serial::query()->where('client_event_id', $issue['client_event_id'])->firstOrFail();
        $this->assertSame(SerialSource::Offline, $serial->source);
        $this->assertSame(1, $serial->number);
        $this->assertSame('A-001', $serial->display_code);
        $this->assertSame($lease['public_id'], $serial->block?->public_id);
        $this->assertSame($device->id, $serial->reception_device_id);
        $this->assertSame($patient->id, $serial->patient_id);
        $this->assertSame($actor->id, $serial->issued_by_user_id);
        $this->assertSame('elderly', $serial->priority->value);
        $this->assertSame(SerialStatus::CheckedIn, $serial->status);
        $this->assertSame(2, $serial->block?->fresh()->next_number, 'block cursor advanced');

        $appointment = Appointment::query()->where('serial_id', $serial->id)->firstOrFail();
        $this->assertSame('offline', $appointment->channel->value);
        $this->assertSame('checked_in', $appointment->status->value);
        $this->assertSame('paid', $appointment->payment_status->value);
        $this->assertSame(80000, $appointment->fee_paisa);
        $this->assertSame($device->id, $appointment->reception_device_id);
        $this->assertSame($issue['client_event_id'], $appointment->client_event_id);
        $this->assertSame('D'.$device->number.'-000001', $json['results'][3]['server_result']['payment']['receipt_no']);

        // SCHEMA §3.5 reserves payments.client_event_id for exactly this: the row is traceable back to the event
        // log entry that produced it (the receipt number stays the idempotency handle).
        $payment = Payment::query()->where('receipt_number', 'D'.$device->number.'-000001')->firstOrFail();
        $this->assertSame($cash['client_event_id'], $payment->client_event_id);
        $this->assertSame($device->id, $payment->reception_device_id);

        Event::assertDispatched(SerialAllocated::class, fn (SerialAllocated $e) => $e->serialId === $serial->id);
        Event::assertDispatched(SerialStatusChanged::class, fn (SerialStatusChanged $e) => $e->serialId === $serial->id && $e->to === 'checked_in');
        Event::assertDispatched(AppointmentBooked::class, fn (AppointmentBooked $e) => $e->appointmentId === $appointment->id);

        $this->assertAudited(AuditAction::Create, $serial);
        $this->assertAudited(AuditAction::CheckIn, $serial);
        $this->assertAudited(AuditAction::Create, $appointment);
        $this->assertAudited(AuditAction::Print, $serial);
        $this->assertSame(1, $session->fresh()->checked_in_count);
        $this->assertNotNull($device->fresh()->last_sync_at);
        $this->assertSame('1.0.0', $device->fresh()->app_version);
    }

    public function test_every_table_the_replay_writes_is_tenant_isolated(): void
    {
        foreach (['offline_events', 'serials', 'appointments', 'serial_blocks'] as $table) {
            $this->assertTenantIsolated($table, function (): void {
                $device = $this->device();
                $actor = $this->receptionist();
                $session = $this->openSession(30, 0, 5);
                $block = $this->leaseFor($device, $session, 5);
                $patient = $this->patientWithMobile('+880171'.random_int(1000000, 9999999));
                $this->sync($device, $actor, [$this->issueEvent(1, $session, $block, $block->next_number, $patient->public_id)])->assertOk()->assertJsonPath('results.0.status', 'accepted');
            });
        }
    }
}
