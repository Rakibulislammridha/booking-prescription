<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Queue\Events\SessionDelayed;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Serials\Actions\DelaySession;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/** REALTIME.md §10 — the one-tap delay broadcast: absolute minutes, shifted ETAs, cleared with 0. */
#[Group('realtime')]
final class SessionDelayTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_delay_is_absolute_not_additive_and_shifts_the_expected_start(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor, start: '09:00', end: '13:00');
        $plannedStart = $session->planned_start_at;   // absolute minutes, never additive

        app(DelaySession::class)->handle($session, 40, $this->queueActor(), 'Doctor is in surgery');
        $this->assertSame(40, $session->fresh()?->delay_minutes);

        // +15 again is an absolute 15, not 55
        app(DelaySession::class)->handle($session->refresh(), 15, $this->queueActor());
        $this->assertSame(15, $session->fresh()?->delay_minutes);

        $state = $this->get('/queue/'.$doctor->slug.'/state')->json();
        $this->assertSame(15, $state['session']['delay_minutes']);
        $this->assertSame($plannedStart->addMinutes(15)->toIso8601ZuluString(), $state['session']['expected_start_at']);
    }

    public function test_a_delay_pushes_every_waiting_serials_eta_back(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-07T01:00:00Z'));   // 07:00 Dhaka, before the session

        try {
            $doctor = $this->queueDoctor();
            $session = $this->queueSession($doctor, start: '09:00', end: '13:00');
            $this->issueMany($session, 3);

            $before = $this->get('/queue/'.$doctor->slug.'/state')->json('serials');
            $this->assertNotNull($before[0]['eta']);

            app(DelaySession::class)->handle($session->fresh(), 45, $this->queueActor());

            $after = $this->get('/queue/'.$doctor->slug.'/state')->json('serials');

            foreach ($before as $i => $row) {
                $this->assertGreaterThan(
                    CarbonImmutable::parse($row['eta'])->getTimestamp(),
                    CarbonImmutable::parse($after[$i]['eta'])->getTimestamp(),
                    'a delay must push the estimate back',
                );
            }
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_clearing_the_delay_broadcasts_zero(): void
    {
        Event::fake([SessionDelayed::class]);

        $session = $this->queueSession();
        app(DelaySession::class)->handle($session, 40, $this->queueActor(), 'surgery');
        app(DelaySession::class)->handle($session->fresh(), 0, $this->queueActor());

        $frames = array_map(fn ($pair) => $pair[0]->payload['delay_minutes'], Event::dispatched(SessionDelayed::class)->all());
        $this->assertSame([40, 0], $frames);
        $this->assertSame(0, $session->fresh()?->delay_minutes);
    }

    public function test_the_delay_notify_bucket_setting_has_the_documented_default(): void
    {
        $this->assertSame(10, (int) app(Settings::class)->get('queue.delay_notify_min_change'));
        $this->assertSame(3, (int) app(Settings::class)->get('queue.notify_ahead'));
        $this->assertSame('both', app(Settings::class)->get('queue.display_voice'));
        $this->assertTrue((bool) app(Settings::class)->get('queue.public_page_enabled'));
    }

    public function test_the_delay_rewrites_the_snapshot_and_bumps_the_version(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $repository = app(QueueStateRepository::class);
        $repository->rebuild($session);
        $before = (int) $repository->version($session->refresh());

        app(DelaySession::class)->handle($session->fresh(), 30, $this->queueActor(), 'traffic');

        $this->assertGreaterThan($before, (int) $repository->version($session->fresh()));
        $snapshot = json_decode((string) $repository->snapshot($session->fresh()), true);
        $this->assertSame(30, $snapshot['session']['delay_minutes']);
    }
}
