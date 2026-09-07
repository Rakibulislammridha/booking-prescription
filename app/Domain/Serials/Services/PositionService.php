<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\NeedsRenormalisation;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * `position` arithmetic of SERIAL_ENGINE §7: bigint with a 1,000,000 gap, midpoint insertion, renormalisation under
 * the session lock. Methods that read the queue expect the caller to hold the session row lock (lockSession()) when
 * the result is written back; between() is pure.
 */
final class PositionService
{
    public const GAP = 1_000_000;

    public function __construct(private readonly SerialEventWriter $events) {}

    /** SELECT id FROM session_instances WHERE id = :sid FOR UPDATE — the reorder lock, taken after the serial row lock. */
    public static function lockSession(int $sessionInstanceId): void
    {
        DB::table('session_instances')->where('id', $sessionInstanceId)->lockForUpdate()->value('id');
    }

    /**
     * Initial position at allocation (§7.1): serial mode max(number × GAP, current max position + GAP) so a reused
     * free-list number joins the tail; slot mode slot_index × GAP.
     */
    public function initial(SessionInstance $s, int $number, SerialPriority $p, ?CarbonImmutable $slot): int
    {
        if ($slot !== null && $s->slot_minutes !== null && $s->slot_minutes > 0) {
            $index = intdiv(max(0, $slot->getTimestamp() - $s->planned_start_at->getTimestamp()), $s->slot_minutes * 60) + 1;

            return $index * self::GAP;
        }

        $currentMax = (int) (DB::table('serials')->where('session_instance_id', $s->id)->max('position') ?? 0);

        return max($number * self::GAP, $currentMax + self::GAP);
    }

    /**
     * Position strictly between two neighbours' positions; head when $before is null, tail when $after is null.
     *
     * @throws NeedsRenormalisation when there is no integer room (b - a < 2, or no room above 0 at the head)
     */
    public function between(?int $before, ?int $after): int
    {
        if ($before === null && $after === null) {
            return self::GAP;
        }

        if ($before === null) {
            if ($after - self::GAP >= 1) {
                return $after - self::GAP;
            }

            if ($after >= 2) {
                return intdiv($after, 2);
            }

            throw new NeedsRenormalisation(0, $after);
        }

        if ($after === null) {
            return $before + self::GAP;
        }

        if ($after - $before < 2) {
            throw new NeedsRenormalisation($before, $after);
        }

        return $before + intdiv($after - $before, 2);
    }

    /**
     * Every non-terminal serial of the instance, ordered by (position, number), gets rank × GAP. Writes one
     * `renormalised` event with the full before/after map in meta. Caller holds the session lock.
     *
     * @return array<int, array{0: int, 1: int}> serial_id => [old, new]
     */
    public function renormalise(SessionInstance $s, ?Actor $actor = null): array
    {
        $rows = DB::table('serials')
            ->where('session_instance_id', $s->id)
            ->whereIn('status', SerialStatus::nonTerminalValues())
            ->orderBy('position')->orderBy('number')
            ->get(['id', 'position']);

        $map = [];
        $before = [];
        $after = [];
        $rank = 1;

        foreach ($rows as $row) {
            $new = $rank * self::GAP;
            $map[(int) $row->id] = [(int) $row->position, $new];
            $before[(string) $row->id] = (int) $row->position;
            $after[(string) $row->id] = $new;
            $rank++;

            if ((int) $row->position !== $new) {
                DB::table('serials')->where('id', (int) $row->id)->update(['position' => $new, 'updated_at' => now()]);
            }
        }

        $this->events->write($s, null, SerialEventType::Renormalised, ['before' => $before, 'after' => $after], $actor);

        return $map;
    }

    /**
     * Position after `skip` waiting serials past now_serving (§7.3): emergency = 0, vip = after the leading
     * emergencies, elderly / reinstate / skip-called = serial.elderly_skip (2). $exclude is the serial being moved.
     * Renormalises (and retries once) when the neighbours have no room.
     */
    public function afterNowServing(SessionInstance $s, int $skip, ?int $exclude = null, ?Actor $actor = null): int
    {
        try {
            return $this->placeAfterWaiting($s, $skip, $exclude);
        } catch (NeedsRenormalisation) {
            $this->renormalise($s, $actor);

            return $this->placeAfterWaiting($s, $skip, $exclude);
        }
    }

    /**
     * Waiting serials (booked|checked_in, positioned after now_serving) in calling order; the moved serial excluded.
     *
     * @return Collection<int, object{id: int, position: int, priority: string, status: string, number: int}>
     */
    public function waiting(SessionInstance $s, ?int $exclude = null): Collection
    {
        $floor = $this->nowServingPosition($s);

        $query = DB::table('serials')
            ->where('session_instance_id', $s->id)
            ->whereIn('status', [SerialStatus::Booked->value, SerialStatus::CheckedIn->value])
            ->orderBy('position')->orderBy('number');

        if ($floor !== null) {
            $query->where('position', '>', $floor);
        }

        if ($exclude !== null) {
            $query->where('id', '<>', $exclude);
        }

        /** @var Collection<int, object{id: int, position: int, priority: string, status: string, number: int}> $rows */
        $rows = $query->get(['id', 'position', 'priority', 'status', 'number']);

        return $rows;
    }

    /** Number of waiting `emergency` serials at the front of the line (the vip rule goes after them). */
    public function leadingEmergencies(SessionInstance $s, ?int $exclude = null): int
    {
        $n = 0;

        foreach ($this->waiting($s, $exclude) as $row) {
            if ($row->priority !== SerialPriority::Emergency->value) {
                break;
            }
            $n++;
        }

        return $n;
    }

    /**
     * Position for a serial going back to `normal` (§7.3): number × GAP, or the tail when that would jump ahead of
     * the serial being served / the first waiting serial, or collide with another live position.
     */
    public function natural(SessionInstance $s, Serial $serial): int
    {
        $target = $serial->number * self::GAP;
        $floor = $this->nowServingPosition($s) ?? 0;
        $first = $this->waiting($s, $serial->id)->first();
        $limit = max($floor, $first === null ? 0 : (int) $first->position);

        $collides = DB::table('serials')
            ->where('session_instance_id', $s->id)
            ->whereIn('status', SerialStatus::nonTerminalValues())
            ->where('id', '<>', $serial->id)
            ->where('position', $target)
            ->exists();

        return ($target < $limit || $collides) ? $this->tail($s, $serial->id) : $target;
    }

    public function tail(SessionInstance $s, ?int $exclude = null): int
    {
        $query = DB::table('serials')->where('session_instance_id', $s->id)->whereIn('status', SerialStatus::nonTerminalValues());

        if ($exclude !== null) {
            $query->where('id', '<>', $exclude);
        }

        return (int) ($query->max('position') ?? 0) + self::GAP;
    }

    public function nowServingPosition(SessionInstance $s): ?int
    {
        if ($s->now_serving_serial_id === null) {
            return null;
        }

        $position = DB::table('serials')->where('id', $s->now_serving_serial_id)->where('status', SerialStatus::InConsultation->value)->value('position');

        return $position === null ? null : (int) $position;
    }

    /** @throws NeedsRenormalisation */
    private function placeAfterWaiting(SessionInstance $s, int $skip, ?int $exclude): int
    {
        $waiting = $this->waiting($s, $exclude)->values();
        $floor = $this->nowServingPosition($s);
        $skip = min($skip, $waiting->count());

        $before = $skip === 0 ? $floor : (int) $waiting[$skip - 1]->position;
        $after = isset($waiting[$skip]) ? (int) $waiting[$skip]->position : null;

        if ($before === null && $after === null) {
            return $this->tail($s, $exclude);
        }

        return $this->between($before, $after);
    }
}
