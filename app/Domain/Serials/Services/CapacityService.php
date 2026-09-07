<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Support\Facades\DB;

/**
 * Capacity queries without locks (SERIAL_ENGINE §12): plain SELECTs over serial_pools/serial_blocks, cached in
 * t:{tenantId}:cap:{sessionInstancePublicId} for 10 s and forgotten by the allocation/cancel/extend paths. The public
 * calendar therefore never touches a locked row and may over-report by one for at most 10 s.
 */
final class CapacityService
{
    public const TTL = 10;

    public function __construct(private readonly Cache $cache) {}

    /** @return array{online: int, counter: int, buffer: int, counter_in_blocks: int, released: int} */
    public function remaining(int $sessionInstanceId): array
    {
        $publicId = (string) DB::table('session_instances')->where('id', $sessionInstanceId)->value('public_id');

        /** @var array{online: int, counter: int, buffer: int, counter_in_blocks: int, released: int} $remaining */
        $remaining = $this->cache->remember(self::key($publicId), self::TTL, fn () => $this->remainingFor([$sessionInstanceId])[$sessionInstanceId] ?? self::empty());

        return $remaining;
    }

    /**
     * Uncached, one round trip per table for many instances.
     *
     * @param  array<int, int>  $ids
     * @return array<int, array{online: int, counter: int, buffer: int, counter_in_blocks: int, released: int}> keyed by session_instance_id
     */
    public function remainingFor(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $out = [];

        foreach ($ids as $id) {
            $out[$id] = self::empty();
        }

        $pools = DB::select('SELECT p.session_instance_id, p.pool, GREATEST(0, p.range_end - p.next_number + 1) AS remaining FROM serial_pools p WHERE p.session_instance_id = ANY(:ids)', ['ids' => '{'.implode(',', $ids).'}']);

        foreach ($pools as $row) {
            $out[(int) $row->session_instance_id][$row->pool] = (int) $row->remaining;
        }

        $blocks = DB::select(<<<'SQL'
            SELECT session_instance_id,
                   COALESCE(SUM(range_end - next_number + 1) FILTER (WHERE status = 'active'), 0)   AS counter_in_blocks,
                   COALESCE(SUM(range_end - next_number + 1) FILTER (WHERE status = 'released'), 0) AS released
            FROM serial_blocks WHERE session_instance_id = ANY(:ids) AND next_number <= range_end GROUP BY 1
            SQL, ['ids' => '{'.implode(',', $ids).'}']);

        foreach ($blocks as $row) {
            $out[(int) $row->session_instance_id]['counter_in_blocks'] = (int) $row->counter_in_blocks;
            $out[(int) $row->session_instance_id]['released'] = (int) $row->released;
        }

        return $out;
    }

    /**
     * Remaining per (date, code) for a doctor at a branch across a date range — the public calendar's numbers
     * (online remaining only is what the site shows).
     *
     * @return array<string, array{session_instance_id: int, public_id: string, status: string, online: int, counter: int, buffer: int, counter_in_blocks: int, released: int}> keyed "YYYY-MM-DD:A"
     */
    public function remainingForRange(int $doctorId, int $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $instances = DB::table('session_instances')
            ->where('doctor_id', $doctorId)->where('branch_id', $branchId)
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('session_date')->orderBy('session_code')
            ->get(['id', 'public_id', 'session_date', 'session_code', 'status']);

        $remaining = $this->remainingFor($instances->pluck('id')->map(fn ($id) => (int) $id)->all());
        $out = [];

        foreach ($instances as $row) {
            $date = CarbonImmutable::parse((string) $row->session_date)->toDateString();
            $out["{$date}:{$row->session_code}"] = ['session_instance_id' => (int) $row->id, 'public_id' => (string) $row->public_id, 'status' => (string) $row->status] + ($remaining[(int) $row->id] ?? self::empty());
        }

        return $out;
    }

    /**
     * Slot mode (§10): planned_start_at .. planned_end_at step slot_minutes, minus the slots held by live serials.
     *
     * @return array<int, string> ISO-8601 UTC slot starts
     */
    public function freeSlots(SessionInstance $s): array
    {
        if ($s->slot_minutes === null || $s->slot_minutes <= 0 || $s->status->isTerminal()) {
            return [];
        }

        $taken = DB::table('serials')
            ->where('session_instance_id', $s->id)
            ->whereNotIn('status', [SerialStatus::Cancelled->value, SerialStatus::Postponed->value, SerialStatus::NoShow->value])
            ->whereNotNull('slot_start_at')
            ->pluck('slot_start_at')
            ->map(fn ($t) => CarbonImmutable::parse((string) $t)->utc()->toIso8601String())
            ->flip();

        $free = [];

        for ($t = $s->planned_start_at->utc(); $t->lt($s->planned_end_at); $t = $t->addMinutes($s->slot_minutes)) {
            $iso = $t->toIso8601String();

            if (! isset($taken[$iso])) {
                $free[] = $iso;
            }
        }

        return $free;
    }

    public function forget(string $sessionPublicId): void
    {
        $this->cache->forget(self::key($sessionPublicId));
    }

    public static function key(string $sessionPublicId): string
    {
        return 't:'.Tenancy::id().':cap:'.$sessionPublicId;
    }

    /** @return array{online: int, counter: int, buffer: int, counter_in_blocks: int, released: int} */
    public static function empty(): array
    {
        return ['online' => 0, 'counter' => 0, 'buffer' => 0, 'counter_in_blocks' => 0, 'released' => 0];
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, SessionStatus::open(), true);
    }
}
