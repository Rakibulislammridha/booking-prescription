<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Serials\Actions\CallNext;
use App\Domain\Serials\Actions\CallSerial;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Actions\MarkNoShow;
use App\Domain\Serials\Actions\PriorityInsert;
use App\Domain\Serials\Actions\ReinstateAfterCancel;
use App\Domain\Serials\Actions\ReinstateSerial;
use App\Domain\Serials\Actions\ReorderSerial;
use App\Domain\Serials\Actions\ReturnToQueue;
use App\Domain\Serials\Actions\StartConsultation;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialCalled;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialCompleted;
use App\Domain\Serials\Events\SerialStatusChanged;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Serials\Exceptions\ReorderStale;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** SERIAL_ENGINE §18.6 — the state machine table of §6, positions of §7, auto no-show of §8. */
final class SerialStateMachineTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    /** @return array<string, array{0: string, 1: string, 2: bool}> */
    public static function transitions(): array
    {
        $all = SerialStatus::values();
        $allowed = SerialTransition::EDGES;
        $cases = [];

        foreach ($all as $from) {
            foreach ($all as $to) {
                $legal = in_array($to, $allowed[$from], true) && $from !== 'cancelled';
                $cases["{$from} -> {$to}"] = [$from, $to, $legal];
            }
        }

        return $cases;
    }

    #[DataProvider('transitions')]
    public function test_every_transition_is_allowed_or_throws_illegal_transition(string $from, string $to, bool $legal): void
    {
        $session = $this->openSession();
        $serial = $this->allocate($session);
        Serial::query()->whereKey($serial->id)->update(['status' => $from]);
        $serial->refresh();

        $transition = app(SerialTransition::class);

        if (! $legal) {
            $this->assertFalse(SerialTransition::allows(SerialStatus::from($from), SerialStatus::from($to)));
            $this->assertThrows(fn () => $transition->apply($serial, SerialStatus::from($to), Actor::system()), IllegalTransition::class);
            $this->assertSame($from, $serial->fresh()->status->value);

            return;
        }

        $versionBefore = $session->fresh()->version;
        $result = $transition->apply($serial, SerialStatus::from($to), $this->staffActor(), ['cancel_reason_code' => CancelReason::Other]);

        $this->assertSame($to, $result->status->value);
        $stamp = match ($to) {
            'checked_in' => 'checked_in_at', 'in_consultation' => 'called_at', 'completed' => 'completed_at',
            'no_show' => 'no_show_at', 'cancelled' => 'cancelled_at', 'postponed' => 'postponed_at', 'booked' => 'reinstated_at',
            default => throw new \LogicException("unexpected target status {$to}"),
        };
        $this->assertNotNull($result->{$stamp}, "{$stamp} not stamped on {$from} -> {$to}");
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $serial->id)->where('from_status', $from)->where('to_status', $to)->count());
        $this->assertSame($versionBefore + 1, $session->fresh()->version);
        $this->assertSame($to === 'booked' ? 1 : 0, $session->fresh()->booked_count);
        $this->assertAudited($to === 'checked_in' ? AuditAction::CheckIn : ($to === 'cancelled' ? AuditAction::Void : AuditAction::Update), $result);
    }

    public function test_cancelled_to_checked_in_only_through_reinstate_after_cancel(): void
    {
        $session = $this->openSession();
        $serial = $this->allocate($session);
        app(CancelSerial::class)->handle($serial, CancelReason::PatientRequest, $this->staffActor());

        $this->assertThrows(fn () => app(CheckInSerial::class)->handle($serial->fresh(), $this->staffActor()), IllegalTransition::class);

        $reinstated = app(ReinstateAfterCancel::class)->handle($serial->fresh(), $this->staffActor(), 'pending');
        $this->assertSame(SerialStatus::CheckedIn, $reinstated->status);
        $this->assertNull($reinstated->cancel_reason_code);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $serial->id)->where('type', SerialEventType::ReinstatedAfterCancel->value)->count());
    }

    public function test_check_in_call_next_complete_flow_stamps_and_updates_now_serving_and_ema(): void
    {
        Event::fake([SerialCalled::class, SerialCompleted::class, SerialStatusChanged::class]);
        $session = $this->openSession();
        [$a, $b] = $this->allocateMany($session, 2);

        $this->assertSame(['called' => null, 'waiting_booked' => 2], app(CallNext::class)->handle($session, $this->staffActor()));

        app(CheckInSerial::class)->handle($b, $this->staffActor());
        app(CheckInSerial::class)->handle($a, $this->staffActor());

        $result = app(CallNext::class)->handle($session, $this->staffActor());
        $called = $result['called'];
        $this->assertInstanceOf(Serial::class, $called);
        $this->assertSame($a->id, $called->id, 'lowest position first');
        $this->assertSame(SerialStatus::InConsultation, $called->status);
        $this->assertNotNull($called->called_at);
        $fresh = $session->fresh();
        $this->assertSame($a->id, $fresh->now_serving_serial_id);
        $this->assertSame('running', $fresh->status->value);
        $this->assertNotNull($fresh->actual_start_at);
        $this->assertSame(0, $result['waiting_booked']);
        Event::assertDispatched(SerialCalled::class, 1);

        app(StartConsultation::class)->handle($called, $this->staffActor());
        $this->assertNotNull($called->fresh()->consultation_started_at);

        Serial::query()->whereKey($a->id)->update(['called_at' => now()->subSeconds(300)]);
        $done = app(CompleteConsultation::class)->handle($a->fresh(), $this->staffActor());
        $this->assertSame(SerialStatus::Completed, $done->status);
        $fresh = $session->fresh();
        $this->assertNull($fresh->now_serving_serial_id);
        $this->assertSame(1, $fresh->consult_samples);
        $this->assertSame((int) round(0.25 * 300 + 0.75 * 360), $fresh->avg_consult_seconds);
        $this->assertSame(1, $fresh->completed_count);
        $this->assertSame(1, $fresh->checked_in_count);
        Event::assertDispatched(SerialCompleted::class, fn (SerialCompleted $e) => $e->durationSeconds === 300);
    }

    public function test_call_serial_from_booked_is_an_implicit_check_in_with_two_events(): void
    {
        $session = $this->openSession();
        $serial = $this->allocate($session);

        $called = app(CallSerial::class)->handle($serial, $this->staffActor());

        $this->assertSame(SerialStatus::InConsultation, $called->status);
        $this->assertNotNull($called->checked_in_at);
        $this->assertNotNull($called->called_at);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $serial->id)->where('type', SerialEventType::Called->value)->count());
        $this->assertThrows(fn () => app(CallSerial::class)->handle($called, $this->staffActor()), IllegalTransition::class);
    }

    public function test_return_to_queue_moves_after_two_waiting_and_counts_skips(): void
    {
        $session = $this->openSession();
        $serials = $this->allocateMany($session, 4);
        foreach ($serials as $s) {
            app(CheckInSerial::class)->handle($s, $this->staffActor());
        }
        $first = app(CallNext::class)->handle($session, $this->staffActor())['called'];

        $returned = app(ReturnToQueue::class)->handle($first, $this->staffActor(), 'stepped out');

        $this->assertSame(SerialStatus::CheckedIn, $returned->status);
        $this->assertSame(1, $returned->skip_count);
        $this->assertNull($session->fresh()->now_serving_serial_id);
        $order = Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('number')->all();
        $this->assertSame([2, 3, 1, 4], $order);
        $this->assertSame(1, SerialEvent::query()->where('serial_id', $first->id)->where('type', SerialEventType::Skipped->value)->count());
    }

    public function test_reorder_and_priority_insert_never_change_number_or_display_code(): void
    {
        $session = $this->openSession();
        [$a, $b, $c, $d] = $this->allocateMany($session, 4);

        $moved = app(ReorderSerial::class)->handle($d, $a->id, $b->id, $this->staffActor(), 'came early');

        $this->assertSame(4, $moved->number);
        $this->assertSame('A-004', $moved->display_code);
        $this->assertSame($a->position + intdiv($b->position - $a->position, 2), $moved->position);
        $this->assertSame([1, 4, 2, 3], Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('number')->all());
        $this->assertAudited(AuditAction::Reorder, $moved, ['after' => 'A-001', 'before' => 'A-002']);
        $event = SerialEvent::query()->where('serial_id', $d->id)->where('type', SerialEventType::Reordered->value)->firstOrFail();
        $this->assertSame(['after' => 'A-001', 'before' => 'A-002'], array_intersect_key($event->meta, ['after' => 1, 'before' => 1]));
        $this->assertSame($d->position, $event->from_position);

        $vip = app(PriorityInsert::class)->handle($c, SerialPriority::Vip, $this->staffActor(), 'donor');
        $this->assertSame(3, $vip->number);
        $this->assertSame('A-003', $vip->display_code);
        $this->assertSame(SerialPriority::Vip, $vip->priority);
        $this->assertSame([3, 1, 4, 2], Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('number')->all());

        // one-sided reorder: after A-002 only → tail
        $back = app(ReorderSerial::class)->handle($vip, $b->id, null, $this->staffActor());
        $this->assertSame([1, 4, 2, 3], Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('number')->all());
        $this->assertSame(3, $back->number);
    }

    public function test_reorder_with_stale_neighbours_is_refused(): void
    {
        $session = $this->openSession();
        [$a, $b, $c, $d] = $this->allocateMany($session, 4);

        // the board thought a and c were adjacent, but b sits between them → 409 reorder_stale
        try {
            app(ReorderSerial::class)->handle($d, $a->id, $c->id, $this->staffActor());
            $this->fail('expected ReorderStale');
        } catch (ReorderStale $e) {
            $this->assertSame('serials.reorder_stale', $e->code());
            $this->assertSame(409, $e->status());
        }

        $this->assertSame([1, 2, 3, 4], Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('number')->all(), 'nothing moved');
        $this->assertThrows(fn () => app(ReorderSerial::class)->handle($d, $c->id, $a->id, $this->staffActor()), ReorderStale::class);   // reversed neighbours
        $this->assertThrows(fn () => app(ReorderSerial::class)->handle($d, null, null, $this->staffActor()), ReorderStale::class);
        app(CancelSerial::class)->handle($c, CancelReason::Other, $this->staffActor());
        $this->assertThrows(fn () => app(ReorderSerial::class)->handle($d, $c->id, null, $this->staffActor()), ReorderStale::class);   // terminal neighbour
        $this->assertThrows(fn () => app(ReorderSerial::class)->handle($d, $this->allocate($this->openSession())->id, null, $this->staffActor()), ReorderStale::class);   // other session
    }

    public function test_midpoint_insert_renormalises_when_no_room(): void
    {
        $session = $this->openSession(30, 0, 5);
        $serials = $this->allocateMany($session, 25);
        [$a, $b] = $serials;

        // 23 consecutive inserts right after A-001 halve the same gap each time (2^20 ≈ 1e6) and force a renormalisation.
        for ($i = 2; $i < 25; $i++) {
            app(ReorderSerial::class)->handle($serials[$i], $a->id, null, $this->staffActor());
        }

        $this->assertGreaterThanOrEqual(1, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::Renormalised->value)->count());
        $positions = Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('position');
        $this->assertSame($positions->unique()->count(), $positions->count(), 'I-POSITION: positions unique among active serials');
        $this->assertSame([1, 25, 24, 23, 22, 21, 20, 19, 18, 17, 16, 15, 14, 13, 12, 11, 10, 9, 8, 7, 6, 5, 4, 3, 2], Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('number')->all());
        $event = SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::Renormalised->value)->firstOrFail();
        $this->assertCount(25, $event->meta['before']);
        $this->assertCount(25, $event->meta['after']);
    }

    public function test_emergency_goes_right_after_now_serving_vip_after_emergencies_elderly_after_two(): void
    {
        $session = $this->openSession(20, 0, 10);
        $serials = $this->allocateMany($session, 6);
        foreach ($serials as $s) {
            app(CheckInSerial::class)->handle($s, $this->staffActor());
        }
        app(CallNext::class)->handle($session, $this->staffActor());   // A-001 in consultation

        $emergency = $this->allocate($session, SerialPool::Buffer, priority: SerialPriority::Emergency);
        $this->assertSame([1, 21, 2, 3, 4, 5, 6], $this->order($session));

        $vip = $this->allocate($session, SerialPool::Buffer, priority: SerialPriority::Vip, priorityReason: 'board member');
        $this->assertSame([1, 21, 22, 2, 3, 4, 5, 6], $this->order($session));

        $elderly = $this->allocate($session, SerialPool::Buffer, priority: SerialPriority::Elderly);
        $this->assertSame([1, 21, 22, 23, 2, 3, 4, 5, 6], $this->order($session), 'elderly after the next 2 waiting (emergency + vip are the two standing)');

        app(PriorityInsert::class)->handle($elderly, SerialPriority::Normal, $this->staffActor());
        $this->assertSame([1, 21, 22, 2, 3, 4, 5, 6, 23], $this->order($session), 'back to normal = tail when number × GAP would jump ahead');
        $this->assertSame(SerialPriority::Normal, $elderly->fresh()->priority);
    }

    public function test_auto_no_show_after_n_passed_and_reinstate_positions_after_two(): void
    {
        Clock::freeze(Clock::today()->format('Y-m-d').' 09:30');   // after planned start (09:00) + grace (10)
        $session = $this->openSession(20, 0, 5);
        $session->forceFill(['auto_noshow_after' => 2])->save();
        $serials = $this->allocateMany($session, 6);   // A-001 .. A-006
        foreach ([1, 2, 3, 4, 5] as $i) {
            app(CheckInSerial::class)->handle($serials[$i], $this->staffActor());   // A-001 stays booked (not arrived)
        }

        app(CallNext::class)->handle($session, $this->staffActor());   // calls A-002: A-001 passed once
        $this->assertSame(1, $serials[0]->fresh()->passed_count);
        $this->assertSame(SerialStatus::Booked, $serials[0]->fresh()->status);

        app(CompleteConsultation::class)->handle($serials[1]->fresh(), $this->staffActor());
        app(CallNext::class)->handle($session, $this->staffActor());   // calls A-003: A-001 passed twice → no_show
        $noShow = $serials[0]->fresh();
        $this->assertSame(SerialStatus::NoShow, $noShow->status);
        $this->assertSame(2, $noShow->passed_count);
        $event = SerialEvent::query()->where('serial_id', $noShow->id)->where('type', SerialEventType::NoShow->value)->firstOrFail();
        $this->assertTrue($event->meta['auto']);
        $this->assertSame('auto', $event->meta['reason']);
        $this->assertSame(2, $event->meta['passed']);
        $this->assertSame(1, $session->fresh()->no_show_count);

        $reinstated = app(ReinstateSerial::class)->handle($noShow, $this->staffActor(), present: true);
        $this->assertSame(SerialStatus::CheckedIn, $reinstated->status);
        $this->assertSame(0, $reinstated->passed_count);
        $this->assertNotNull($reinstated->reinstated_at);
        // now serving A-003; waiting A-004, A-005 (checked in), A-006 (booked) → reinstated A-001 after the next two
        $this->assertSame([3, 4, 5, 1, 6], $this->order($session));

        $again = app(MarkNoShow::class)->handle($reinstated, $this->staffActor());
        $this->assertSame(SerialStatus::NoShow, $again->status);
        $back = app(ReinstateSerial::class)->handle($again, $this->staffActor(), present: false);
        $this->assertSame(SerialStatus::Booked, $back->status);
    }

    public function test_auto_no_show_respects_grace_and_never_touches_checked_in(): void
    {
        Clock::freeze(Clock::today()->format('Y-m-d').' 09:05');   // inside the 10-minute grace
        $session = $this->openSession(20, 0, 5);
        $session->forceFill(['auto_noshow_after' => 1])->save();
        [$a, $b, $c] = $this->allocateMany($session, 3);
        app(CheckInSerial::class)->handle($a, $this->staffActor());
        app(CheckInSerial::class)->handle($c, $this->staffActor());

        app(CallSerial::class)->handle($c, $this->staffActor());   // A-001 (checked in) and A-002 (booked) are passed
        $this->assertSame(SerialStatus::CheckedIn, $a->fresh()->status);
        $this->assertSame(SerialStatus::Booked, $b->fresh()->status, 'no auto no-show inside the grace period');
        $this->assertSame(1, $b->fresh()->passed_count);
        $this->assertSame(0, $a->fresh()->passed_count, 'checked_in serials are present and never counted');
    }

    public function test_cancel_emits_refund_eligibility_by_role_and_cutoff(): void
    {
        Event::fake([SerialCancelled::class]);
        Clock::freeze(Clock::today()->format('Y-m-d').' 07:30');   // 90 min before the 09:00 start
        $session = $this->openSession();

        $byPatient = $this->allocate($session, SerialPool::Online);
        $result = app(CancelSerial::class)->handle($byPatient, CancelReason::PatientRequest, new Actor(patientId: 501, source: 'web'));
        $this->assertTrue($result['refund_eligible'], 'patient before the 60-minute cutoff');
        $this->assertSame(CancelReason::PatientRequest, $result['serial']->cancel_reason_code);

        Clock::freeze(Clock::today()->format('Y-m-d').' 08:30');   // 30 min before
        $late = $this->allocate($session, SerialPool::Online);
        $this->assertFalse(app(CancelSerial::class)->handle($late, CancelReason::PatientRequest, new Actor(patientId: 502, source: 'web'))['refund_eligible']);

        $byDesk = $this->allocate($session, SerialPool::Counter);
        $this->assertTrue(app(CancelSerial::class)->handle($byDesk, CancelReason::DoctorUnavailable, Actor::user(1, Role::Receptionist->value))['refund_eligible'], 'clinic cancellations are always refund-eligible');

        Event::assertDispatched(SerialCancelled::class, 3);
        Event::assertDispatched(SerialCancelled::class, fn (SerialCancelled $e) => $e->cancelledByRole === 'patient' && $e->minutesBeforePlannedStart === 90 && $e->refundEligible);
        Event::assertDispatched(SerialCancelled::class, fn (SerialCancelled $e) => $e->cancelledByRole === 'receptionist' && $e->reasonCode === 'doctor_unavailable');
        $this->assertSame(3, $session->fresh()->cancelled_count);
    }

    public function test_display_code_and_position_survive_every_transition(): void
    {
        $session = $this->openSession();
        $serial = $this->allocate($session);
        app(CheckInSerial::class)->handle($serial, $this->staffActor());
        app(CallSerial::class)->handle($serial, $this->staffActor());
        app(CompleteConsultation::class)->handle($serial, $this->staffActor());

        $fresh = $serial->fresh();
        $this->assertSame(1, $fresh->number);
        $this->assertSame('A-001', $fresh->display_code);
        $this->assertSame(PositionService::GAP, $fresh->position);
        $this->assertSame(4, SerialEvent::query()->where('serial_id', $serial->id)->count());   // booked, checked_in, called, completed
    }

    /** @return array<int, int> */
    private function order(SessionInstance $session): array
    {
        return Serial::query()->where('session_instance_id', $session->id)->active()->queueOrder()->pluck('number')->all();
    }
}
