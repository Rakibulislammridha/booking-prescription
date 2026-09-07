<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Queue\Events\SerialApproaching;
use App\Domain\Serials\Actions\MarkNoShow;
use App\Domain\Serials\Actions\ReinstateSerial;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/**
 * REALTIME.md §7 — the "3 ahead" trigger. The dedupe IS the `t3_notified_at IS NULL` predicate inside one
 * `UPDATE … RETURNING`, so calling twice can never notify the same patient twice.
 */
#[Group('realtime')]
final class ThreeAheadNotificationTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @return array<int, Serial> */
    private function queueOf(int $n): array
    {
        $this->session = $this->queueSession($this->queueDoctor(), 'A', counter: 30, online: 5, buffer: 5);
        $serials = $this->issueMany($this->session, $n);

        foreach ($serials as $serial) {
            $this->checkIn($serial);
        }

        return array_map(fn (Serial $s) => $s->fresh(), $serials);
    }

    private SessionInstance $session;

    public function test_notifies_the_serials_at_distance_three_exactly_once(): void
    {
        Event::fake([SerialApproaching::class]);
        $serials = $this->queueOf(8);

        $this->callNext($this->session->fresh());

        // ahead 0,1,2,3 → the four behind the one now in the chamber
        Event::assertDispatchedTimes(SerialApproaching::class, 4);
        $codes = array_map(fn ($pair) => $pair[0]->displayCode, Event::dispatched(SerialApproaching::class)->all());
        $this->assertSame([$serials[1]->display_code, $serials[2]->display_code, $serials[3]->display_code, $serials[4]->display_code], $codes);
        $this->assertSame([0, 1, 2, 3], array_map(fn ($pair) => $pair[0]->ahead, Event::dispatched(SerialApproaching::class)->all()));

        // every notified row is stamped
        $this->assertSame(4, Serial::query()->where('session_instance_id', $this->session->id)->whereNotNull('t3_notified_at')->count());
    }

    public function test_calling_twice_notifies_each_patient_only_once(): void
    {
        Event::fake([SerialApproaching::class]);
        $this->queueOf(8);

        $this->callNext($this->session->fresh());
        Event::assertDispatchedTimes(SerialApproaching::class, 4);

        $this->callNext($this->session->fresh());

        // only the serial that has just entered the window is new
        Event::assertDispatchedTimes(SerialApproaching::class, 5);
        $this->assertSame(5, Serial::query()->where('session_instance_id', $this->session->id)->whereNotNull('t3_notified_at')->count());
    }

    public function test_no_shows_collapsing_the_line_notify_with_the_actual_ahead_count(): void
    {
        $this->queueOf(8);
        $serials = Serial::query()->where('session_instance_id', $this->session->id)->orderBy('position')->get();

        // the first call stamps the four behind the called one
        $this->callNext($this->session->fresh());

        Event::fake([SerialApproaching::class]);

        // three of the stamped ones vanish; the next call must notify the ones that moved up, with their real distance
        foreach ([$serials[1], $serials[2], $serials[3]] as $gone) {
            app(MarkNoShow::class)->handle($gone->fresh(), $this->queueActor());
        }

        $this->callNext($this->session->fresh());

        $aheads = array_map(fn ($pair) => $pair[0]->ahead, Event::dispatched(SerialApproaching::class)->all());
        $this->assertNotEmpty($aheads);
        foreach ($aheads as $ahead) {
            $this->assertLessThanOrEqual(3, $ahead);
            $this->assertGreaterThanOrEqual(0, $ahead);
        }
    }

    public function test_a_reinstated_serial_is_not_renotified(): void
    {
        $this->queueOf(8);
        $this->callNext($this->session->fresh());

        $notified = Serial::query()->where('session_instance_id', $this->session->id)->whereNotNull('t3_notified_at')->orderBy('position')->first();
        $this->assertNotNull($notified);
        $stampedAt = $notified->t3_notified_at;

        app(ReinstateSerial::class)->handle(app(MarkNoShow::class)->handle($notified->fresh(), $this->queueActor()), $this->queueActor());

        Event::fake([SerialApproaching::class]);
        $this->callNext($this->session->fresh());

        $codes = array_map(fn ($pair) => $pair[0]->displayCode, Event::dispatched(SerialApproaching::class)->all());
        $this->assertNotContains($notified->display_code, $codes, 'reinstate does not reset t3_notified_at');
        $this->assertEquals($stampedAt, $notified->fresh()?->t3_notified_at);
    }

    public function test_it_respects_the_tenant_notify_ahead_setting(): void
    {
        app(Settings::class)->set('queue.notify_ahead', 1);

        Event::fake([SerialApproaching::class]);
        $this->queueOf(8);
        $this->callNext($this->session->fresh());

        Event::assertDispatchedTimes(SerialApproaching::class, 2);   // ahead 0 and 1
    }

    public function test_the_event_carries_the_ids_the_notifications_module_needs(): void
    {
        Event::fake([SerialApproaching::class]);
        $this->queueOf(5);
        $this->callNext($this->session->fresh());

        Event::assertDispatched(SerialApproaching::class, function (SerialApproaching $e): bool {
            $this->assertSame($this->session->id, $e->sessionInstanceId);
            $this->assertSame($this->session->public_id, $e->sessionPublicId);
            $this->assertSame(26, strlen($e->serialPublicId));
            $this->assertMatchesRegularExpression('/^A-\d{3}$/', $e->displayCode);
            $this->assertGreaterThan(0, $e->serialId);

            return true;
        });
    }
}
