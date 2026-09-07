<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Queue\Listeners\InvalidateQueueState;
use App\Domain\Queue\QueueServiceProvider;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Actions\CancelSerial;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Serials\Actions\CloseSession;
use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Actions\DelaySession;
use App\Domain\Serials\Actions\ExtendSessionCapacity;
use App\Domain\Serials\Actions\MarkNoShow;
use App\Domain\Serials\Actions\PauseSession;
use App\Domain\Serials\Actions\PostponeSerial;
use App\Domain\Serials\Actions\PriorityInsert;
use App\Domain\Serials\Actions\ReinstateSerial;
use App\Domain\Serials\Actions\ReorderSerial;
use App\Domain\Serials\Actions\ResumeSession;
use App\Domain\Serials\Actions\StartSession;
use App\Domain\Serials\Actions\TransferSerial;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialPriority;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/** REALTIME.md §4.3 — every listed domain event rebuilds the snapshot synchronously after commit. */
#[Group('realtime')]
final class QueueStateInvalidationTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @return array<string, array{0: string}> */
    public static function transitions(): array
    {
        return array_map(fn (string $name) => [$name], array_combine(
            $keys = ['allocate', 'check_in', 'call', 'complete', 'cancel', 'no_show', 'reinstate', 'postpone',
                'transfer', 'reorder', 'priority_insert', 'extend', 'delay', 'cancel_session', 'close_session',
                'start_session', 'pause', 'resume'],
            $keys,
        ));
    }

    #[DataProvider('transitions')]
    public function test_every_listed_domain_event_bumps_version_and_rewrites_the_snapshot(string $transition): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $repository = app(QueueStateRepository::class);

        $serial = $this->issue($session);
        $repository->rebuild($session->refresh());
        $before = $repository->version($session->refresh());
        $this->assertSame($session->version, $before);

        $this->apply($transition, $session, $serial->fresh());

        $session->refresh();
        $after = $repository->version($session);

        $this->assertGreaterThan((int) $before, (int) $after, "{$transition} did not bump the version");
        $this->assertSame($session->version, $after, 'the Redis mirror must equal session_instances.version');

        $snapshot = json_decode((string) $repository->snapshot($session), true);
        $this->assertIsArray($snapshot);
        $this->assertSame($session->version, $snapshot['version'], 'the document must be rewritten, not just the mirror');
    }

    public function test_the_listener_is_registered_for_every_event_the_spec_lists(): void
    {
        foreach (QueueServiceProvider::INVALIDATING_EVENTS as $event) {
            $listeners = array_map(
                fn ($closure) => (new \ReflectionFunction($closure))->getStaticVariables()['listener'] ?? null,
                app('events')->getListeners($event),
            );
            $this->assertContains(InvalidateQueueState::class, $listeners, "{$event} has no InvalidateQueueState listener");
        }

        $this->assertCount(19, QueueServiceProvider::INVALIDATING_EVENTS);
    }

    public function test_ttl_is_at_least_two_hours_and_extends_to_planned_end_plus_two_hours(): void
    {
        $session = $this->queueSession($this->queueDoctor('dr-morning'), start: '09:00', end: '13:00');
        $tenantId = (int) Tenancy::id();
        $repository = app(QueueStateRepository::class);

        $repository->rebuild($session);
        $ttl = (int) Redis::ttl($repository->key($tenantId, $session->public_id));
        $expected = QueueStateRepository::ttlFor($session->refresh());

        $this->assertGreaterThanOrEqual(QueueStateRepository::TTL_MIN, $ttl);
        $this->assertLessThanOrEqual($expected + 5, $ttl);
        $this->assertSame((int) Redis::ttl($repository->versionKey($tenantId, $session->public_id)), $ttl);

        // a session that ends late in the day gets a TTL reaching past planned_end + 2 h
        $late = $this->queueSession($this->queueDoctor('dr-evening'), start: '18:00', end: '23:00');
        $repository->rebuild($late);
        $this->assertGreaterThan(QueueStateRepository::TTL_MIN, (int) Redis::ttl($repository->key($tenantId, $late->public_id)));
    }

    public function test_transfer_invalidates_both_sessions(): void
    {
        $source = $this->queueSession($this->queueDoctor('dr-source'), 'A');
        $target = $this->queueSession($this->queueDoctor('dr-target'), 'A');
        $serial = $this->issue($source, Patient::factory()->create()->id);
        $repository = app(QueueStateRepository::class);

        $repository->rebuild($source->refresh());
        $repository->rebuild($target->refresh());
        $sourceBefore = (int) $repository->version($source->refresh());
        $targetBefore = (int) $repository->version($target->refresh());

        app(TransferSerial::class)->handle($serial->fresh(), $target->fresh(), $this->queueActor(), 'doctor unavailable');

        $this->assertGreaterThan($sourceBefore, (int) $repository->version($source->refresh()));
        $this->assertGreaterThan($targetBefore, (int) $repository->version($target->refresh()));
    }

    public function test_the_eta_refresh_command_rebuilds_only_running_sessions_with_a_stale_call(): void
    {
        $repository = app(QueueStateRepository::class);

        $running = $this->queueSession($this->queueDoctor('dr-running'), 'A');
        $this->checkIn($this->issue($running));
        $this->callNext($running);
        $running->refresh();
        $running->forceFill(['last_called_at' => now()->subMinutes(5)])->save();

        $justCalled = $this->queueSession($this->queueDoctor('dr-fresh'), 'A');
        $this->checkIn($this->issue($justCalled));
        $this->callNext($justCalled);
        $justCalled->refresh();

        $scheduled = $this->queueSession($this->queueDoctor('dr-scheduled'), 'A');
        $this->issue($scheduled);
        $repository->rebuild($scheduled->refresh());

        $versions = [
            'running' => (int) $repository->version($running),
            'justCalled' => (int) $repository->version($justCalled),
            'scheduled' => (int) $repository->version($scheduled->refresh()),
        ];

        $this->artisan('queue:refresh-eta')->assertSuccessful();

        $this->assertGreaterThan($versions['running'], (int) $repository->version($running->refresh()), 'a stale running session is refreshed');
        $this->assertSame($versions['justCalled'], (int) $repository->version($justCalled->refresh()), 'a session called seconds ago is left alone');
        $this->assertSame($versions['scheduled'], (int) $repository->version($scheduled->refresh()), 'a scheduled session is left alone');
    }

    public function test_the_eta_refresh_command_is_the_only_writer_of_the_estimated_call_at_cache(): void
    {
        $doctor = $this->queueDoctor('dr-eta-cache');
        $session = $this->queueSession($doctor);
        $serials = $this->issueMany($session, 3);

        // the after-commit rebuild must NOT touch serials: a multi-row UPDATE there deadlocks against live allocations
        foreach ($serials as $serial) {
            $this->assertNull($serial->fresh()?->estimated_call_at);
        }

        $this->checkIn($serials[0]);
        $this->callNext($session->fresh());
        $this->artisan('queue:refresh-eta', ['--stale' => 0])->assertSuccessful();

        $waiting = Serial::query()->where('session_instance_id', $session->id)->whereIn('status', ['booked', 'checked_in'])->get();
        $this->assertNotEmpty($waiting);
        foreach ($waiting as $serial) {
            $this->assertNotNull($serial->estimated_call_at, 'queue:refresh-eta fills the slip/SMS cache column');
        }
    }

    public function test_a_slower_writer_never_overwrites_a_newer_snapshot(): void
    {
        $session = $this->queueSession();
        $repository = app(QueueStateRepository::class);
        $repository->rebuild($session->refresh());

        // simulate a writer that read an older row: bump the DB version, rebuild, then rebuild from the stale model
        $stale = $session->fresh();
        $this->issue($session);
        $session->refresh();
        $newest = (int) $repository->version($session);

        $repository->rebuild($stale);   // rebuild() re-reads the row under the lock

        $this->assertSame($newest, (int) $repository->version($session->refresh()));
    }

    private function apply(string $transition, SessionInstance $session, Serial $serial): void
    {
        $actor = $this->queueActor();

        match ($transition) {
            'allocate' => $this->issue($session),
            'check_in' => $this->checkIn($serial),
            'call' => $this->callAfterCheckIn($session, $serial),
            'complete' => app(CompleteConsultation::class)->handle($this->callAfterCheckIn($session, $serial), $actor),
            'cancel' => app(CancelSerial::class)->handle($serial, CancelReason::PatientRequest, $actor),
            'no_show' => app(MarkNoShow::class)->handle($serial, $actor),
            'reinstate' => app(ReinstateSerial::class)->handle(app(MarkNoShow::class)->handle($serial, $actor), $actor),
            'postpone' => app(PostponeSerial::class)->handle($serial, $this->queueSession($session->doctor, 'B', start: '15:00', end: '19:00'), $actor, 'later'),
            'transfer' => app(TransferSerial::class)->handle($serial, $this->queueSession($this->queueDoctor('dr-target'), 'A'), $actor, 'transfer'),
            'reorder' => app(ReorderSerial::class)->handle($serial, null, $this->issue($session)->id, $actor),
            'priority_insert' => app(PriorityInsert::class)->handle($serial, SerialPriority::Emergency, $actor, 'emergency'),
            'extend' => app(ExtendSessionCapacity::class)->handle($session, 5, $actor, 'more patients'),
            'delay' => app(DelaySession::class)->handle($session, 40, $actor, 'Doctor is in surgery'),
            'cancel_session' => app(CancelSession::class)->handle($session, $actor, 'doctor ill'),
            'close_session' => app(CloseSession::class)->handle($session, $actor),
            'start_session' => app(StartSession::class)->handle($session, $actor),
            'pause' => app(PauseSession::class)->handle(app(StartSession::class)->handle($session, $actor), $actor, 'tea'),
            'resume' => app(ResumeSession::class)->handle(app(PauseSession::class)->handle(app(StartSession::class)->handle($session, $actor), $actor), $actor),
            default => throw new \InvalidArgumentException($transition),
        };
    }

    private function callAfterCheckIn(SessionInstance $session, Serial $serial): Serial
    {
        $this->checkIn($serial);
        $called = $this->callNext($session->fresh());
        $this->assertNotNull($called);
        $this->assertSame(SessionStatus::Running, $session->fresh()?->status);

        return $called;
    }
}
