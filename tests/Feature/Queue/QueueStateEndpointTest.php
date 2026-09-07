<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Actions\CloseSession;
use App\Models\Tenant\Patient;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/** REALTIME.md §5.2 / §13.1 — the LOCKED polling endpoint: ETag == version, 304 costs one Redis GET, no PII. */
#[Group('realtime')]
final class QueueStateEndpointTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_returns_200_with_etag_equal_to_version_and_no_cache_headers(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->issueMany($session, 2);
        $session->refresh();

        $response = $this->get('/queue/'.$doctor->slug.'/state');

        $response->assertOk()
            ->assertHeader('ETag', '"'.$session->version.'"')
            ->assertHeader('Cache-Control', 'no-cache, private')
            ->assertHeader('X-Queue-Session', $session->public_id)
            ->assertHeader('Content-Type', 'application/json; charset=utf-8');

        $state = $response->json();
        $this->assertSame($session->version, $state['version']);
        $this->assertSame($session->public_id, $state['session']['id']);
        $this->assertCount(2, $state['serials']);
    }

    public function test_returns_304_when_if_none_match_equals_version_and_performs_no_snapshot_read(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->issue($session);
        $session->refresh();

        $repository = app(QueueStateRepository::class);
        $tenantId = (int) Tenancy::id();

        // Drop the document but KEEP the `:v` mirror: a 304 that still works proves the path is one GET of the
        // version and never touches the JSON (REALTIME.md §5.2).
        Redis::del($repository->key($tenantId, $session->public_id));
        $this->assertNull($repository->snapshot($session));
        $this->assertSame($session->version, $repository->version($session));

        $response = $this->get('/queue/'.$doctor->slug.'/state', ['If-None-Match' => '"'.$session->version.'"']);

        $response->assertStatus(304)
            ->assertHeader('ETag', '"'.$session->version.'"')
            ->assertHeader('Cache-Control', 'no-cache, private')
            ->assertHeader('X-Queue-Session', $session->public_id);
        $this->assertSame('', $response->getContent());
        $this->assertNull($repository->snapshot($session), 'the 304 path must not rebuild either');
    }

    public function test_returns_200_with_a_new_version_after_a_serial_event(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $first = $this->get('/queue/'.$doctor->slug.'/state');
        $etag = (string) $first->headers->get('ETag');

        $this->get('/queue/'.$doctor->slug.'/state', ['If-None-Match' => $etag])->assertStatus(304);

        $this->issue($session);

        $second = $this->get('/queue/'.$doctor->slug.'/state', ['If-None-Match' => $etag]);
        $second->assertOk();
        $this->assertNotSame($etag, $second->headers->get('ETag'));
        $this->assertGreaterThan((int) trim($etag, '"'), $second->json('version'));
    }

    public function test_picks_running_session_by_default_then_next_scheduled_then_last_closed(): void
    {
        $doctor = $this->queueDoctor();
        $morning = $this->queueSession($doctor, 'A');
        $afternoon = $this->queueSession($doctor, 'B', start: '15:00', end: '19:00');

        // both scheduled → the first by planned start
        $this->get('/queue/'.$doctor->slug.'/state')->assertHeader('X-Queue-Session', $morning->public_id);

        // one running → that one, whatever the order
        $afternoon->forceFill(['status' => SessionStatus::Running])->save();
        $this->get('/queue/'.$doctor->slug.'/state')->assertHeader('X-Queue-Session', $afternoon->public_id);

        // none open → the last of the day, so a late visitor sees "session ended"
        app(CloseSession::class)->handle($morning->fresh(), $this->queueActor());
        app(CloseSession::class)->handle($afternoon->fresh(), $this->queueActor());
        $this->get('/queue/'.$doctor->slug.'/state')->assertHeader('X-Queue-Session', $afternoon->public_id);
    }

    public function test_pins_the_session_via_query_and_404s_for_an_unknown_slug_or_another_doctor(): void
    {
        $doctor = $this->queueDoctor();
        $a = $this->queueSession($doctor, 'A');
        $b = $this->queueSession($doctor, 'B', start: '15:00', end: '19:00');

        $this->get('/queue/'.$doctor->slug.'/state?session='.$b->public_id)->assertOk()->assertHeader('X-Queue-Session', $b->public_id);
        $this->assertNotSame($a->public_id, $b->public_id);

        $other = $this->queueSession($this->queueDoctor('dr-other'), 'C');
        $this->get('/queue/'.$doctor->slug.'/state?session='.$other->public_id)->assertNotFound();
        $this->get('/queue/dr-nobody/state')->assertNotFound();
    }

    public function test_another_tenants_session_id_is_a_404_not_a_leak(): void
    {
        $this->asTenant('b');
        $foreign = $this->queueSession($this->queueDoctor('dr-b'), 'A');

        $this->asTenant('a');
        $doctor = $this->queueDoctor('dr-a');
        $this->queueSession($doctor, 'A');

        $this->get('/queue/'.$doctor->slug.'/state?session='.$foreign->public_id)->assertNotFound();
    }

    public function test_the_payload_contains_no_patient_identifiers(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $serials = $this->issueMany($session, 3, withPatients: true);
        $this->checkIn($serials[0]);

        $state = $this->get('/queue/'.$doctor->slug.'/state')->json();

        foreach ($state['serials'] as $row) {
            $this->assertSame(['id', 'c', 'n', 'p', 's', 'eta', 'ahead'], array_keys($row));
        }

        $json = json_encode($state, JSON_UNESCAPED_UNICODE);
        foreach ($serials as $serial) {
            $patient = Patient::query()->findOrFail($serial->patient_id);
            $this->assertStringNotContainsString($patient->name, (string) $json);
            $this->assertStringNotContainsString($patient->mobile, (string) $json);
            $this->assertStringNotContainsString($patient->patient_code, (string) $json);
        }
    }

    public function test_the_public_page_and_endpoint_can_be_switched_off_per_tenant(): void
    {
        $doctor = $this->queueDoctor();
        $this->queueSession($doctor);

        app(Settings::class)->set('queue.public_page_enabled', false);
        $this->get('/q/'.$doctor->slug.'/today')->assertNotFound();
        $this->get('/queue/'.$doctor->slug.'/state')->assertOk();   // the poll endpoint stays available to a page already open
    }

    public function test_throttle_applies_after_thirty_requests_per_minute(): void
    {
        RateLimiter::clear('queue-state:127.0.0.1:');
        $doctor = $this->queueDoctor();
        $this->queueSession($doctor);

        for ($i = 0; $i < 30; $i++) {
            $this->get('/queue/'.$doctor->slug.'/state')->assertSuccessful();
        }

        $this->get('/queue/'.$doctor->slug.'/state')->assertStatus(429);
        RateLimiter::clear('queue-state:127.0.0.1:');
    }

    public function test_a_cold_cache_rebuilds_inline_and_answers_with_the_committed_version(): void
    {
        $doctor = $this->queueDoctor();
        $session = $this->queueSession($doctor);
        $this->issue($session);
        $session->refresh();

        app(QueueStateRepository::class)->forget($session);
        $this->assertNull(app(QueueStateRepository::class)->version($session));

        $this->get('/queue/'.$doctor->slug.'/state')
            ->assertOk()
            ->assertHeader('ETag', '"'.$session->version.'"')
            ->assertJsonPath('version', $session->version);

        $this->assertSame($session->version, app(QueueStateRepository::class)->version($session));
        $this->assertSame(Clock::today()->toDateString(), $session->session_date->toDateString());
    }
}
