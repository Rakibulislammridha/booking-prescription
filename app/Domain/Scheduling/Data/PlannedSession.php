<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Data;

use App\Domain\Scheduling\Enums\ScheduleMode;
use Carbon\CarbonImmutable;

/** One session the planner derived for a (branch, doctor, date) after applying precedence (SERIAL_ENGINE §2.1). */
final readonly class PlannedSession
{
    /** @param  array<int, int>  $overrideIds  schedule_overrides absorbed by this plan (stamped applied_at on creation) */
    public function __construct(
        public string $sessionCode,
        public CarbonImmutable $plannedStartAt,   // UTC instant
        public CarbonImmutable $plannedEndAt,
        public ScheduleMode $mode,
        public ?int $slotMinutes,
        public int $counterQuota,
        public int $onlineQuota,
        public int $bufferQuota,
        public int $avgConsultSeconds,
        public int $autoNoshowAfter,
        public int $feeNewPaisa,
        public int $feeFollowupPaisa,
        public ?int $doctorScheduleId,
        public int $delayMinutes = 0,
        public array $overrideIds = [],
    ) {}

    public function maxSerials(): int
    {
        return $this->counterQuota + $this->onlineQuota + $this->bufferQuota;
    }

    /** @param  array<string, mixed>  $overrides */
    public function with(array $overrides): self
    {
        return new self(...array_merge(get_object_vars($this), $overrides));
    }
}
