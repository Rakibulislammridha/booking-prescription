<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Services\CapacityService;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Per-day availability of a doctor at a branch for the public site's calendar (SERIAL_ENGINE §12, §16): materialises
 * the range on demand, then reports each session's online remaining and quota (the capacity the site draws), the
 * serial now being served when a session is running, and, in slot mode, the free slot starts. A day with no session
 * says why (`closed`: not in the weekly template, a holiday, or doctor leave — read from the same tables the planner
 * consults, two range queries for the whole calendar). Never takes a lock.
 */
final class AvailabilityCalendar
{
    public const MAX_DAYS = 62;

    public function __construct(
        private readonly SessionMaterialiser $materialiser,
        private readonly CapacityService $capacity,
    ) {}

    /**
     * @return array<int, array{date: string, closed: string|null, sessions: array<int, array<string, mixed>>}>
     */
    public function days(int $doctorId, int $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $from = $from->setTimezone(Clock::timezone())->startOfDay();
        $to = min($to->setTimezone(Clock::timezone())->startOfDay(), $from->addDays(self::MAX_DAYS - 1));

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $this->materialiser->ensureDay($branchId, $doctorId, $date);
        }

        $instances = SessionInstance::query()
            ->where('doctor_id', $doctorId)->where('branch_id', $branchId)
            ->whereBetween('session_date', [$from->toDateString(), $to->toDateString()])
            ->orderBy('session_date')->orderBy('session_code')
            ->get();

        $remaining = $this->capacity->remainingFor($instances->pluck('id')->all());
        $nowServing = $this->nowServing($instances);
        $closures = $this->closures($doctorId, $branchId, $from, $to);
        $days = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $days[$date->toDateString()] = ['date' => $date->toDateString(), 'closed' => null, 'sessions' => []];
        }

        foreach ($instances as $s) {
            $key = $s->session_date->toDateString();
            $days[$key]['sessions'][] = [
                'code' => $s->session_code,
                'public_id' => $s->public_id,
                'status' => $s->status->value,
                'mode' => $s->mode->value,
                'planned_start_at' => $s->planned_start_at->toIso8601String(),
                'planned_end_at' => $s->planned_end_at->toIso8601String(),
                'delay_minutes' => $s->delay_minutes,
                'online_remaining' => $s->acceptsSerials() ? ($remaining[$s->id]['online'] ?? 0) : 0,
                'online_quota' => (int) $s->online_quota,
                'slot_minutes' => $s->mode === ScheduleMode::Slot ? $s->slot_minutes : null,
                'now_serving' => $nowServing[$s->id] ?? null,
                'free_slots' => $s->mode === ScheduleMode::Slot && $s->acceptsSerials() ? $this->capacity->freeSlots($s) : null,
            ];
        }

        foreach ($days as $key => $day) {
            if ($day['sessions'] === []) {
                $days[$key]['closed'] = $closures[$key] ?? 'off';
            }
        }

        return array_values($days);
    }

    /**
     * Why a day in the range has no session, with the planner's precedence (SessionPlanner: leaves > holidays):
     * "YYYY-MM-DD" => leave|holiday. Days that are simply not in the weekly template are absent (the caller says `off`).
     *
     * @return array<string, string>
     */
    private function closures(int $doctorId, int $branchId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        $branchScoped = fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId);

        $holidays = Holiday::query()->whereBetween('holiday_date', [$from->toDateString(), $to->toDateString()])->where($branchScoped)->pluck('holiday_date');

        foreach ($holidays as $holiday) {
            $out[$holiday->toDateString()] = 'holiday';
        }

        $leaves = DoctorLeave::query()->where('doctor_id', $doctorId)->where('is_cancelled', false)
            ->whereDate('starts_on', '<=', $to->toDateString())->whereDate('ends_on', '>=', $from->toDateString())
            ->where($branchScoped)->get(['starts_on', 'ends_on']);

        // Calendar dates compared as strings: the leave rows are midnight in the app zone, the range midnight in Dhaka.
        foreach ($leaves as $leave) {
            $last = min($leave->ends_on->toDateString(), $to->toDateString());

            for ($date = CarbonImmutable::parse(max($leave->starts_on->toDateString(), $from->toDateString()), Clock::timezone()); $date->toDateString() <= $last; $date = $date->addDay()) {
                $out[$date->toDateString()] = 'leave';
            }
        }

        return $out;
    }

    /**
     * Display code of the serial each RUNNING session is serving right now — one query for the whole range, none when
     * nothing is running (the common case: a calendar of future days).
     *
     * @param  Collection<int, SessionInstance>  $instances
     * @return array<int, string> keyed by session_instance_id
     */
    private function nowServing(Collection $instances): array
    {
        $serialIds = $instances
            ->filter(fn (SessionInstance $s) => $s->status === SessionStatus::Running && $s->now_serving_serial_id !== null)
            ->pluck('now_serving_serial_id', 'id');

        if ($serialIds->isEmpty()) {
            return [];
        }

        $codes = Serial::query()->whereIn('id', $serialIds->values()->all())->pluck('display_code', 'id');

        return $serialIds->map(fn (int $serialId) => $codes[$serialId] ?? null)->filter()->all();
    }
}
