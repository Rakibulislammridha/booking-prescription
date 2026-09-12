<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Serials\Actions\CallNext;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\PriorityInsert;
use App\Domain\Serials\Enums\SerialPriority;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/**
 * The board lists a session's rows by serial NUMBER — the order the tokens were handed out and the one the waiting
 * room reads — not by the engine's queue position, which the desk experienced as random once check-ins and priority
 * inserts had reshuffled it (SERIAL_ENGINE §7). Because "Call next" still follows the position, the board names
 * the row it would take (`next_serial`), and that name must be exactly CallNext's own pick.
 */
final class DeskBoardOrderTest extends TestCase
{
    use ReceptionFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @return array<string, mixed> the session's row of the board JSON */
    private function boardSession(string $publicId): array
    {
        /** @var array{sessions: array<int, array<string, mixed>>} $json */
        $json = $this->getJson(route('panel.reception.board.data', [], false))->assertOk()->json();

        return $this->rowWith($json['sessions'], 'public_id', $publicId);
    }

    /**
     * The one row of a JSON list whose `$key` is `$value`; fails the test when there is none (so the caller gets a
     * typed array, not a nullable one).
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowWith(array $rows, string $key, string $value): array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        self::fail("no row with {$key} = {$value}");
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<int, mixed>
     */
    private function codes(array $session): array
    {
        /** @var array<int, array<string, mixed>> $serials */
        $serials = $session['serials'];

        return array_map(fn (array $s) => $s['display_code'], $serials);
    }

    public function test_rows_are_listed_by_number_and_the_row_call_next_would_take_is_named(): void
    {
        $session = $this->openSession();
        $actor = $this->staffActor();
        [$s1, $s2, $s3, $s4] = array_map(fn () => $this->allocate($session, patientId: Patient::factory()->create()->id), range(1, 4));
        $this->actingAsStaff(Role::Receptionist);

        // Nobody has arrived: the list is 1, 2, 3, 4 and there is nothing to call.
        $row = $this->boardSession($session->public_id);
        $this->assertSame([$s1->display_code, $s2->display_code, $s3->display_code, $s4->display_code], $this->codes($row));
        $this->assertNull($row['next_serial']);

        // 3 arrives before 2 — the queue is 3, 2 (check-in order), the list is still 1, 2, 3, 4.
        app(CheckInSerial::class)->handle($s3, $actor);
        app(CheckInSerial::class)->handle($s2, $actor);
        $row = $this->boardSession($session->public_id);
        $this->assertSame([$s1->display_code, $s2->display_code, $s3->display_code, $s4->display_code], $this->codes($row));
        $this->assertSame($s2->public_id, $row['next_serial']['public_id'], 'positions are untouched by check-in, so the lowest number waiting is next');
        $this->assertSame($s2->display_code, $row['next_serial']['display_code']);

        // A priority insert moves 4 to the head of the queue: the list does not move, the chip does.
        app(CheckInSerial::class)->handle($s4, $actor);
        app(PriorityInsert::class)->handle($s4->fresh(), SerialPriority::Emergency, $actor, 'collapsed at the door');
        $row = $this->boardSession($session->public_id);
        $this->assertSame([$s1->display_code, $s2->display_code, $s3->display_code, $s4->display_code], $this->codes($row), 'a priority insert changes position, never the listed order');
        $this->assertSame($s4->public_id, $row['next_serial']['public_id']);
        /** @var array<int, array<string, mixed>> $serials */
        $serials = $row['serials'];
        $four = $this->rowWith($serials, 'public_id', $s4->public_id);
        $two = $this->rowWith($serials, 'public_id', $s2->public_id);
        $this->assertLessThan($two['position'], $four['position'], 'the row still carries the queue position the chip is derived from');
        $this->assertSame('emergency', $four['priority']);

        // ...and the chip was telling the truth: CallNext takes exactly that row.
        $called = app(CallNext::class)->handle($session->fresh(), $actor)['called'];
        $this->assertInstanceOf(Serial::class, $called);
        $this->assertSame($s4->public_id, $called->public_id);

        $row = $this->boardSession($session->public_id);
        $this->assertSame($s4->display_code, $row['now_serving']['display_code']);
        $this->assertSame($s2->public_id, $row['next_serial']['public_id'], 'once 4 is in consultation the head of the queue is 2 again');
        $this->assertSame([$s1->display_code, $s2->display_code, $s3->display_code, $s4->display_code], $this->codes($row));
    }

    public function test_next_of_applies_the_same_rule_as_the_call_next_query(): void
    {
        $session = $this->openSession();
        $actor = $this->staffActor();
        [$a, $b, $c] = array_map(fn () => $this->allocate($session, patientId: Patient::factory()->create()->id), range(1, 3));
        app(CheckInSerial::class)->handle($b, $actor);
        app(CheckInSerial::class)->handle($c, $actor);

        $rows = Serial::query()->where('session_instance_id', $session->id)->orderBy('number')->get();
        $this->assertSame($b->public_id, CallNext::nextOf($rows)?->public_id, 'lowest position among the checked in; a booked row ahead of it does not count');

        // A tie on position is broken by number, as the query's ORDER BY position, number does.
        Serial::query()->whereKey($c->id)->update(['position' => $b->position]);
        $this->assertSame($b->public_id, CallNext::nextOf(Serial::query()->where('session_instance_id', $session->id)->orderByDesc('number')->get())?->public_id);
        $this->assertNull(CallNext::nextOf([$a]));
        $this->assertNull(CallNext::nextOf([]));
    }

    /**
     * The prescription handle joined ONE more grouped query to the board (IssuedPrescriptionQuery) and nothing per
     * row. Writing this test found that the board was not in fact bounded before: SerialPresenter `loadMissing`s
     * each row's `sessionInstance`, one query per serial, which BoardBuilder now hands over from the session it
     * already has. So the arithmetic is 20 bounded queries at HEAD + 1 for the handle = 21 (HEAD measured 25 for
     * five rows: 20 + five of that N+1), and — the part that actually matters — the count does not move when the
     * session grows.
     */
    public function test_the_board_runs_a_bounded_number_of_queries(): void
    {
        $doctor = $this->doctorWithTemplate();
        $session = $this->openSession(10, 10, 5, $doctor);
        $serials = [];

        for ($i = 0; $i < 5; $i++) {
            $serials[] = $this->allocate($session, patientId: Patient::factory()->create()->id);
        }

        $this->actingAsStaff(Role::Receptionist);
        app(CheckInSerial::class)->handle($serials[1], $this->staffActor());
        app(CheckInSerial::class)->handle($serials[2], $this->staffActor());

        $this->getJson(route('panel.reception.board.data', [], false))->assertOk();   // warm the per-request caches
        $five = $this->countQueries(fn () => $this->getJson(route('panel.reception.board.data', [], false))->assertOk());
        $this->assertSame(21, $five, 'HEAD ran 20 bounded queries + one per row; now 20 + one grouped query for the prescription handle');

        for ($i = 0; $i < 4; $i++) {
            $serials[] = $this->allocate($session, patientId: Patient::factory()->create()->id);
        }

        app(CheckInSerial::class)->handle($serials[6], $this->staffActor());
        app(CheckInSerial::class)->handle($serials[8], $this->staffActor());

        $this->getJson(route('panel.reception.board.data', [], false))->assertOk();
        $nine = $this->countQueries(fn () => $this->getJson(route('panel.reception.board.data', [], false))->assertOk());
        $this->assertSame($five, $nine, 'no query is per row');
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
