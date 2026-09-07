<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Actions\CallNext;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Serials\Actions\ChangePoolSplit;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\CloseSession;
use App\Domain\Serials\Actions\DelaySession;
use App\Domain\Serials\Actions\ExtendSessionCapacity;
use App\Domain\Serials\Actions\LeaseBlock;
use App\Domain\Serials\Actions\PauseSession;
use App\Domain\Serials\Actions\ReleaseOnlineToCounter;
use App\Domain\Serials\Actions\ResumeSession;
use App\Domain\Serials\Actions\StartSession;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\DoctorArrived;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Serials\Events\SessionCancelled;
use App\Domain\Serials\Events\SessionCapacityExtended;
use App\Domain\Serials\Events\SessionClosed;
use App\Domain\Serials\Events\SessionDelayed;
use App\Domain\Serials\Exceptions\ExtensionNotPermitted;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Serials\Exceptions\SessionNotAcceptingSerials;
use App\Domain\Serials\Exceptions\SplitLocked;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SerialPool as PoolRow;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Event;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** SERIAL_ENGINE §2.5 lifecycle, §3.4 extension, §3.6 split rules, §18.2/§18.6 close behaviour. */
final class SessionLifecycleTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_start_pause_resume_close_lifecycle(): void
    {
        Event::fake([DoctorArrived::class, SessionClosed::class]);
        $session = $this->openSession();

        $this->assertThrows(fn () => app(PauseSession::class)->handle($session, $this->staffActor()), IllegalSessionState::class);
        $this->assertThrows(fn () => app(ResumeSession::class)->handle($session, $this->staffActor()), IllegalSessionState::class);

        $running = app(StartSession::class)->handle($session, $this->staffActor());
        $this->assertSame(SessionStatus::Running, $running->status);
        $this->assertNotNull($running->actual_start_at);
        Event::assertDispatched(DoctorArrived::class, 1);
        $this->assertSame($running->id, app(StartSession::class)->handle($session, $this->staffActor())->id, 'start is idempotent');

        $paused = app(PauseSession::class)->handle($session, $this->staffActor(), 'tea');
        $this->assertSame(SessionStatus::Paused, $paused->status);
        $this->travel(90)->seconds();
        $resumed = app(ResumeSession::class)->handle($session, $this->staffActor());
        $this->assertSame(SessionStatus::Running, $resumed->status);
        $this->assertGreaterThanOrEqual(90, $resumed->pause_seconds);
        $this->assertNull($resumed->notes);

        $closed = app(CloseSession::class)->handle($session, $this->staffActor());
        $this->assertSame(SessionStatus::Closed, $closed->status);
        $this->assertNotNull($closed->actual_end_at);
        $this->assertSame(1, $closed->closed_by_user_id);
        Event::assertDispatched(SessionClosed::class, 1);
        $this->assertThrows(fn () => app(StartSession::class)->handle($session, $this->staffActor()), IllegalSessionState::class);
        $this->assertThrows(fn () => app(CancelSession::class)->handle($session, $this->staffActor()), IllegalSessionState::class);
        $this->assertThrows(fn () => $this->allocate($session), SessionNotAcceptingSerials::class);
    }

    public function test_close_session_no_shows_remaining_and_releases_blocks(): void
    {
        Event::fake([SerialNoShow::class, SessionClosed::class]);
        $session = $this->openSession(20, 5, 5);
        [$a, $b, $c] = $this->allocateMany($session, 3);
        app(CheckInSerial::class)->handle($b, $this->staffActor());
        app(CheckInSerial::class)->handle($c, $this->staffActor());
        app(CallNext::class)->handle($session, $this->staffActor());   // b in consultation
        $this->ensureDevices(9);
        $block = app(LeaseBlock::class)->handle($session, 9, 5, $this->staffActor(), 5);

        $closed = app(CloseSession::class)->handle($session, $this->staffActor(), 'end of day');

        $this->assertSame(SessionStatus::Closed, $closed->status);
        $this->assertSame(SerialStatus::NoShow, $a->fresh()->status);
        $this->assertSame(SerialStatus::InConsultation, $b->fresh()->status, 'a patient with the doctor is not touched');
        $this->assertSame(SerialStatus::NoShow, $c->fresh()->status);
        $this->assertSame(2, $closed->no_show_count);
        $this->assertNull($closed->now_serving_serial_id);
        $events = SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::NoShow->value)->get();
        $this->assertCount(2, $events);
        $this->assertTrue($events->every(fn (SerialEvent $e) => $e->meta['reason'] === 'session_closed'));
        Event::assertDispatched(SerialNoShow::class, fn (SerialNoShow $e) => $e->reason === 'session_closed');

        $block->refresh();
        $this->assertSame(BlockStatus::Released, $block->status);
        $this->assertSame(5, $block->returned_count);
        $this->assertNotNull($block->released_at);
        $this->assertSame(1, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::BlockReleased->value)->count());
        $this->assertSame($closed->id, app(CloseSession::class)->handle($session, Actor::system(), 'stale')->id, 'close is idempotent');
    }

    public function test_cancel_session_cancels_every_live_serial_with_reason_session_cancelled(): void
    {
        Event::fake([SerialCancelled::class, SessionCancelled::class]);
        $session = $this->openSession();
        [$a, $b] = $this->allocateMany($session, 2);
        app(CheckInSerial::class)->handle($b, $this->staffActor());

        $cancelled = app(CancelSession::class)->handle($session, Actor::user(1, Role::HospitalAdmin->value), 'doctor sick');

        $this->assertSame(SessionStatus::Cancelled, $cancelled->status);
        $this->assertSame('doctor sick', $cancelled->cancel_reason);
        $this->assertSame(SerialStatus::Cancelled, $a->fresh()->status);
        $this->assertSame(CancelReason::SessionCancelled, $b->fresh()->cancel_reason_code);
        $this->assertSame(2, $cancelled->cancelled_count);
        Event::assertDispatched(SerialCancelled::class, 2);
        Event::assertDispatched(SerialCancelled::class, fn (SerialCancelled $e) => $e->refundEligible && $e->reasonCode === 'session_cancelled');
        Event::assertDispatched(SessionCancelled::class, fn (SessionCancelled $e) => $e->values['notify_patients'] === true);
        $this->assertThrows(fn () => $this->allocate($session), SessionNotAcceptingSerials::class);
    }

    public function test_delay_is_absolute_and_bumps_the_version(): void
    {
        Event::fake([SessionDelayed::class]);
        $session = $this->openSession();
        $v = $session->version;

        $delayed = app(DelaySession::class)->handle($session, 40, $this->staffActor(), 'traffic');
        $this->assertSame(40, $delayed->delay_minutes);
        $this->assertSame($v + 1, $delayed->fresh()->version);
        $this->assertSame($session->planned_start_at->addMinutes(40)->getTimestamp(), $delayed->expectedStartAt()->getTimestamp());

        $delayed = app(DelaySession::class)->handle($session, 15, $this->staffActor());
        $this->assertSame(15, $delayed->delay_minutes, 'absolute, not additive');
        $this->assertSame(2, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::Delayed->value)->count());
        Event::assertDispatched(SessionDelayed::class, 2);
    }

    public function test_extend_capacity_appends_above_max(): void
    {
        Event::fake([SessionCapacityExtended::class]);
        $session = $this->openSession(10, 10, 2);
        $this->allocateMany($session, 2, SerialPool::Buffer);
        $this->assertThrows(fn () => $this->allocate($session, SerialPool::Buffer), PoolExhausted::class);

        $extended = app(ExtendSessionCapacity::class)->handle($session, 5, Actor::user(1, Role::Doctor->value), 'doctor agreed');

        $this->assertSame(27, $extended->max_serials);
        $this->assertSame(7, $extended->buffer_quota);
        $this->assertSame(27, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'buffer')->value('range_end'));
        $walkin = $this->allocate($session, SerialPool::Buffer);
        $this->assertSame(23, $walkin->number, 'max_serials_old + 1');
        $event = SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::CapacityExtended->value)->firstOrFail();
        $this->assertSame(5, $event->meta['by']);
        Event::assertDispatched(SessionCapacityExtended::class, 1);
        $this->assertThrows(fn () => app(ExtendSessionCapacity::class)->handle($session, 0, $this->staffActor()), IllegalSessionState::class);
    }

    public function test_receptionist_extension_is_capped_by_the_tenant_setting(): void
    {
        $receptionist = User::factory()->withRole(Role::Receptionist)->create();
        $session = $this->openSession();
        $actor = Actor::user($receptionist->id, Role::Receptionist->value);

        $this->assertThrows(fn () => app(ExtendSessionCapacity::class)->handle($session, 1, $actor), ExtensionNotPermitted::class);

        app(Settings::class)->set('serial.receptionist_extension_limit', 3);
        app(ExtendSessionCapacity::class)->handle($session, 2, $actor);
        $this->assertThrows(fn () => app(ExtendSessionCapacity::class)->handle($session, 2, $actor), ExtensionNotPermitted::class);
        app(ExtendSessionCapacity::class)->handle($session, 1, $actor);
        $this->assertSame(28, $session->fresh()->max_serials);
    }

    public function test_change_pool_split_rewrites_ranges_only_while_untouched(): void
    {
        $session = $this->openSession(10, 10, 5);

        $changed = app(ChangePoolSplit::class)->handle($session, 15, 5, Actor::user(1, Role::Doctor->value));
        $pools = PoolRow::query()->where('session_instance_id', $session->id)->get()->keyBy(fn (PoolRow $p) => $p->pool->value);

        $this->assertSame([1, 15], [$pools['counter']->range_start, $pools['counter']->range_end]);
        $this->assertSame([16, 20], [$pools['online']->range_start, $pools['online']->range_end]);
        $this->assertSame([21, 25], [$pools['buffer']->range_start, $pools['buffer']->range_end]);
        $this->assertSame([15, 5, 25], [$changed->counter_quota, $changed->online_quota, $changed->max_serials]);
        $this->assertSame(1, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::SplitChanged->value)->count());

        $this->allocate($session, SerialPool::Online);
        try {
            app(ChangePoolSplit::class)->handle($session, 12, 8, Actor::user(1, Role::Doctor->value));
            $this->fail('expected SplitLocked');
        } catch (SplitLocked $e) {
            $this->assertSame('serials.split_locked', $e->code());
            $this->assertSame(409, $e->status());
        }
    }

    public function test_release_online_to_counter_creates_a_desk_owned_released_block(): void
    {
        $session = $this->openSession(10, 10, 5);
        $this->allocateMany($session, 3, SerialPool::Online);   // 11, 12, 13 issued; 14..20 unissued

        $result = app(ReleaseOnlineToCounter::class)->handle($session, null, Actor::user(1, Role::Doctor->value));

        $block = $result['released_block'];
        $this->assertNotNull($block);
        $this->assertSame([14, 20], $result['range']);
        $this->assertNull($block->reception_device_id);
        $this->assertSame(BlockStatus::Released, $block->status);
        $this->assertSame(14, $block->next_number);
        $online = PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'online')->firstOrFail();
        $this->assertSame(13, $online->range_end);
        $this->assertSame($online->id, $block->serial_pool_id, 'the released range still belongs to the online pool');
        $this->assertThrows(fn () => $this->allocate($session, SerialPool::Online), PoolExhausted::class);

        // the counter drains the released range before its own cursor, lowest first
        $this->assertSame(14, $this->allocate($session, SerialPool::Counter)->number);
        $this->assertSame(15, $this->allocate($session, SerialPool::Counter)->number);
        $this->assertSame(1, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $this->assertSame(1, SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::OnlineReleased->value)->count());

        // partial release of the top k
        $second = $this->openSession(10, 10, 5);
        $partial = app(ReleaseOnlineToCounter::class)->handle($second, 4, Actor::user(1, Role::Doctor->value));
        $this->assertSame([17, 20], $partial['range']);
        $this->assertSame(11, $this->allocate($second, SerialPool::Online)->number, 'online still issues its remaining low numbers');
        $this->assertThrows(fn () => app(ReleaseOnlineToCounter::class)->handle($second, 9, Actor::user(1, Role::Doctor->value)), SplitLocked::class);
    }

    public function test_blocks_are_released_on_cancel_too(): void
    {
        $session = $this->openSession(20, 0, 0);
        $this->ensureDevices(3);
        $block = app(LeaseBlock::class)->handle($session, 3, 5, $this->staffActor(), 5);
        app(CancelSession::class)->handle($session, Actor::user(1, Role::HospitalAdmin->value));

        $this->assertSame(BlockStatus::Released, $block->fresh()->status);
        $this->assertSame(0, Serial::query()->where('session_instance_id', $session->id)->count());
        $this->assertSame(1, SerialBlock::query()->where('session_instance_id', $session->id)->count());
    }
}
