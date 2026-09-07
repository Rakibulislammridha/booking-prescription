<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Services\CapacityService;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * The `board.updated` payload (REALTIME.md §3.1): one row per today's session at a branch with the counts, the
 * remaining pool numbers and the queue version. Codes only — the desk fetches patient rows from its own endpoints.
 */
final class BoardStateBuilder
{
    public const VERSION = 1;

    public function __construct(private readonly CapacityService $capacity) {}

    /** @return array<string, mixed> */
    public function build(Branch $branch, ?CarbonImmutable $date = null): array
    {
        $date ??= Clock::today();

        $sessions = SessionInstance::query()->with('doctor')
            ->where('branch_id', $branch->id)
            ->whereDate('session_date', $date->toDateString())
            ->orderBy('planned_start_at')->orderBy('session_code')
            ->get();

        $remaining = $this->capacity->remainingFor($sessions->pluck('id')->all());

        return [
            'v' => self::VERSION,
            'branch' => $branch->public_id,
            'at' => now()->toIso8601ZuluString(),
            'sessions' => $sessions->map(function (SessionInstance $s) use ($remaining): array {
                $pools = $remaining[$s->id] ?? ['online' => 0, 'counter' => 0, 'buffer' => 0, 'counter_in_blocks' => 0, 'released' => 0];

                return [
                    'id' => $s->public_id,
                    'doctor' => $s->doctor->public_id,
                    'code' => $s->session_code,
                    'status' => $s->status->value,
                    'now_serving' => $s->now_serving_serial_id === null ? null : $s->nowServing?->display_code,
                    'counts' => [
                        'booked' => $s->booked_count, 'checked_in' => $s->checked_in_count, 'in_consultation' => $s->in_consultation_count,
                        'completed' => $s->completed_count, 'no_show' => $s->no_show_count, 'cancelled' => $s->cancelled_count, 'postponed' => $s->postponed_count,
                    ],
                    'remaining' => [
                        'online' => $pools['online'], 'counter' => $pools['counter'],
                        'released' => $pools['released'], 'buffer' => $pools['buffer'],
                    ],
                    'delay_minutes' => $s->delay_minutes,
                    'version' => $s->version,
                ];
            })->values()->all(),
        ];
    }

    /**
     * Display tiles for a branch (REALTIME.md §9.1): the running/scheduled/paused sessions, newest state first.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tiles(Branch $branch, ?CarbonImmutable $date = null): array
    {
        $date ??= Clock::today();

        return SessionInstance::query()->with('doctor')
            ->where('branch_id', $branch->id)
            ->whereDate('session_date', $date->toDateString())
            ->whereIn('status', [SessionStatus::Running->value, SessionStatus::Scheduled->value, SessionStatus::Paused->value])
            ->orderBy('planned_start_at')->orderBy('session_code')
            ->get()
            ->map(fn (SessionInstance $s) => [
                'id' => $s->public_id,
                'code' => $s->session_code,
                'doctor' => ['id' => $s->doctor->public_id, 'slug' => $s->doctor->slug, 'name' => $s->doctor->name, 'name_bn' => $s->doctor->name_bn, 'room' => $s->doctor->room_label],
            ])->values()->all();
    }
}
