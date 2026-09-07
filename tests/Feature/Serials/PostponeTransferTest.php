<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\PostponeSerial;
use App\Domain\Serials\Actions\TransferSerial;
use App\Domain\Serials\Actions\TransferSession;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialPostponed;
use App\Domain\Serials\Events\SerialTransferred;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Serials\Exceptions\NoNextSession;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Serials\Exceptions\TransferTargetInvalid;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** SERIAL_ENGINE §9 / §18.6. */
final class PostponeTransferTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_postpone_links_both_directions_and_is_idempotent(): void
    {
        Event::fake([SerialPostponed::class]);
        $doctor = Doctor::factory()->complete()->create();
        $morning = $this->openSession(10, 10, 5, $doctor);
        $evening = SessionInstance::factory()->on($this->today(), 'B', '17:00', '21:00')->quotas(10, 10, 5)->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);

        $serial = $this->allocate($morning, SerialPool::Online, priority: SerialPriority::Elderly);
        app(CheckInSerial::class)->handle($serial, $this->staffActor());

        $result = app(PostponeSerial::class)->handle($serial, null, $this->staffActor(), 'doctor delayed');

        $old = $result['old'];
        $new = $result['new'];
        $this->assertSame(SerialStatus::Postponed, $old->status);
        $this->assertNotNull($old->postponed_at);
        $this->assertSame($new->id, $old->postponed_to_serial_id);
        $this->assertSame($old->id, $new->transferred_from_serial_id);
        $this->assertSame($evening->id, $new->session_instance_id);
        $this->assertSame('B-011', $new->display_code);
        $this->assertSame(SerialPool::Online, $new->pool, 'online stays online');
        $this->assertSame(SerialPriority::Elderly, $new->priority);
        $this->assertSame(PostponeSerial::clientEventId($old), $new->client_event_id);
        $this->assertSame(26, strlen((string) $new->client_event_id));
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $old->id)->where('type', SerialEventType::Postponed->value)->where('meta->to_serial', 'B-011')->count());
        $this->assertSame(1, $morning->fresh()->postponed_count);
        Event::assertDispatched(SerialPostponed::class, fn (SerialPostponed $e) => $e->old['display_code'] === 'A-011' && $e->new['display_code'] === 'B-011');

        // a retried request finds the same target serial and cannot postpone a postponed serial twice
        $this->assertThrows(fn () => app(PostponeSerial::class)->handle($old->fresh(), $evening, $this->staffActor()), IllegalTransition::class);
        $this->assertSame(1, Serial::query()->where('session_instance_id', $evening->id)->count());

        $counter = $this->allocate($morning, SerialPool::Counter);
        $this->assertSame(SerialPool::Counter, app(PostponeSerial::class)->handle($counter, $evening, $this->staffActor())['new']->pool);
        $walkin = $this->allocate($morning, SerialPool::Buffer);
        $this->assertSame(SerialPool::Counter, app(PostponeSerial::class)->handle($walkin, $evening, $this->staffActor())['new']->pool, 'everything else → counter');
    }

    public function test_postpone_without_a_later_session_or_to_another_doctor_is_refused(): void
    {
        $session = $this->openSession();
        $serial = $this->allocate($session);
        $this->assertThrows(fn () => app(PostponeSerial::class)->handle($serial, null, $this->staffActor()), NoNextSession::class);

        $other = $this->openSession();
        $this->assertThrows(fn () => app(PostponeSerial::class)->handle($serial, $other, $this->staffActor()), TransferTargetInvalid::class);
        $this->assertSame(SerialStatus::Booked, $serial->fresh()->status, 'nothing changed');
    }

    public function test_postpone_rolls_back_when_the_target_is_exhausted(): void
    {
        $doctor = Doctor::factory()->complete()->create();
        $morning = $this->openSession(10, 10, 5, $doctor);
        $evening = SessionInstance::factory()->on($this->today(), 'B', '17:00', '21:00')->quotas(0, 0, 0)->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        $serial = $this->allocate($morning);

        $this->assertThrows(fn () => app(PostponeSerial::class)->handle($serial, $evening, $this->staffActor()), PoolExhausted::class);
        $this->assertSame(SerialStatus::Booked, $serial->fresh()->status);
        $this->assertNull($serial->fresh()->postponed_to_serial_id);
        $this->assertSame(0, SerialEvent::query()->where('serial_id', $serial->id)->where('type', SerialEventType::Postponed->value)->count());
    }

    public function test_transfer_cancels_old_with_reason_transferred_and_emits_fee_delta(): void
    {
        Event::fake([SerialTransferred::class]);
        $source = $this->openSession();
        $target = $this->openSession();
        $target->forceFill(['fee_new_paisa' => 100000])->save();
        $serial = $this->allocate($source, SerialPool::Counter, SerialSource::Followup);

        $result = app(TransferSerial::class)->handle($serial, $target, $this->staffActor(), 'doctor unavailable');

        $old = $result['old'];
        $new = $result['new'];
        $this->assertSame(SerialStatus::Cancelled, $old->status);
        $this->assertSame(CancelReason::Transferred, $old->cancel_reason_code);
        $this->assertSame($new->id, $old->transferred_to_serial_id);
        $this->assertSame($old->id, $new->transferred_from_serial_id);
        $this->assertSame($target->id, $new->session_instance_id);
        $this->assertSame(SerialSource::Followup, $new->source);
        $this->assertSame(TransferSerial::clientEventId($old), $new->client_event_id);
        $this->assertSame(20000, $result['fee_delta_expected']);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $old->id)->where('type', SerialEventType::TransferredOut->value)->count());
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $new->id)->where('type', SerialEventType::TransferredIn->value)->count());
        $this->assertSame(1, $source->fresh()->cancelled_count);
        $this->assertSame(1, $target->fresh()->booked_count);
        Event::assertDispatched(SerialTransferred::class, fn (SerialTransferred $e) => $e->oldFeeSnapshot === 80000 && $e->targetFee === 100000 && $e->feeDeltaExpected() === 20000);
        $this->assertAudited(AuditAction::Transfer, $old);

        $sameDoctor = SessionInstance::factory()->on($this->today(), 'B', '17:00', '21:00')->create(['doctor_id' => $source->doctor_id, 'branch_id' => $this->mainBranch()->id]);
        $this->assertThrows(fn () => app(TransferSerial::class)->handle($this->allocate($source), $sameDoctor, $this->staffActor()), TransferTargetInvalid::class);
    }

    public function test_transfer_session_moves_every_live_serial_and_stops_at_exhaustion(): void
    {
        $source = $this->openSession(10, 10, 5);
        $target = $this->openSession(3, 0, 0);
        $serials = $this->allocateMany($source, 5);
        app(CancelSerial::class)->handle($serials[1], CancelReason::PatientRequest, $this->staffActor());

        $report = app(TransferSession::class)->handle($source, $target, $this->staffActor(), 'doctor unavailable');

        $this->assertCount(3, $report['transferred']);
        $this->assertSame('A-005', $report['stopped_at']);
        $this->assertSame(1, $report['remaining']);
        $this->assertSame(3, Serial::query()->where('session_instance_id', $target->id)->count());
        $this->assertSame(SerialStatus::Booked, $serials[4]->fresh()->status, 'the one after exhaustion is untouched');
        $this->assertSame([1, 3, 4], Serial::query()->where('session_instance_id', $target->id)->orderBy('number')->get()->map(fn (Serial $s) => (int) Serial::query()->whereKey($s->transferred_from_serial_id)->value('number'))->all());
    }
}
