<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Domain\Serials\Services\CapacityService;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Per-day availability of a doctor at a branch for the public site's calendar (SERIAL_ENGINE §12, §16): materialises
 * the range on demand, then reports each session's online remaining (the only number the site shows) and, in slot
 * mode, the free slot starts. Never takes a lock.
 */
final class AvailabilityCalendar
{
    public const MAX_DAYS = 62;

    public function __construct(
        private readonly SessionMaterialiser $materialiser,
        private readonly CapacityService $capacity,
    ) {}

    /**
     * @return array<int, array{date: string, sessions: array<int, array<string, mixed>>}>
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
        $days = [];

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $days[$date->toDateString()] = ['date' => $date->toDateString(), 'sessions' => []];
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
                'free_slots' => $s->mode === ScheduleMode::Slot && $s->acceptsSerials() ? $this->capacity->freeSlots($s) : null,
            ];
        }

        return array_values($days);
    }
}
