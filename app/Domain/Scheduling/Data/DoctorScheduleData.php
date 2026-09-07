<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Data;

use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final readonly class DoctorScheduleData
{
    public function __construct(
        public int $doctorId,
        public int $branchId,
        public int $weekday,
        public string $sessionCode,
        public string $startTime,
        public string $endTime,
        public int $counterQuota,
        public int $onlineQuota,
        public int $bufferQuota = 4,
        public ScheduleMode $mode = ScheduleMode::Serial,
        public ?int $slotMinutes = null,
        public ?string $sessionLabel = null,
        public int $avgConsultMinutes = 6,
        public ?int $feeNewPaisa = null,
        public ?int $feeFollowupPaisa = null,
        public ?int $autoNoshowAfter = null,
        public bool $worksOnHolidays = false,
        public ?CarbonImmutable $effectiveFrom = null,
        public ?CarbonImmutable $effectiveTo = null,
        public bool $isActive = true,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            doctorId: (int) $v['doctor_id'],
            branchId: (int) $v['branch_id'],
            weekday: (int) $v['weekday'],
            sessionCode: strtoupper((string) $v['session_code']),
            startTime: (string) $v['start_time'],
            endTime: (string) $v['end_time'],
            counterQuota: (int) $v['counter_quota'],
            onlineQuota: (int) $v['online_quota'],
            bufferQuota: (int) ($v['buffer_quota'] ?? 4),
            mode: ScheduleMode::from((string) ($v['mode'] ?? ScheduleMode::Serial->value)),
            slotMinutes: isset($v['slot_minutes']) ? (int) $v['slot_minutes'] : null,
            sessionLabel: $v['session_label'] ?? null,
            avgConsultMinutes: (int) ($v['avg_consult_minutes'] ?? 6),
            feeNewPaisa: isset($v['fee_new_paisa']) ? (int) $v['fee_new_paisa'] : null,
            feeFollowupPaisa: isset($v['fee_followup_paisa']) ? (int) $v['fee_followup_paisa'] : null,
            autoNoshowAfter: isset($v['auto_noshow_after']) ? (int) $v['auto_noshow_after'] : null,
            worksOnHolidays: (bool) ($v['works_on_holidays'] ?? false),
            effectiveFrom: isset($v['effective_from']) ? CarbonImmutable::parse((string) $v['effective_from'])->startOfDay() : null,
            effectiveTo: isset($v['effective_to']) ? CarbonImmutable::parse((string) $v['effective_to'])->startOfDay() : null,
            isActive: (bool) ($v['is_active'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'doctor_id' => $this->doctorId,
            'branch_id' => $this->branchId,
            'weekday' => $this->weekday,
            'session_code' => $this->sessionCode,
            'session_label' => $this->sessionLabel,
            'start_time' => $this->startTime,
            'end_time' => $this->endTime,
            'mode' => $this->mode,
            'slot_minutes' => $this->mode === ScheduleMode::Slot ? $this->slotMinutes : null,
            'max_serials' => $this->counterQuota + $this->onlineQuota + $this->bufferQuota,
            'online_quota' => $this->onlineQuota,
            'counter_quota' => $this->counterQuota,
            'buffer_quota' => $this->bufferQuota,
            'avg_consult_minutes' => $this->avgConsultMinutes,
            'fee_new_paisa' => $this->feeNewPaisa,
            'fee_followup_paisa' => $this->feeFollowupPaisa,
            'auto_noshow_after' => $this->autoNoshowAfter,
            'works_on_holidays' => $this->worksOnHolidays,
            'effective_from' => ($this->effectiveFrom ?? Clock::today())->toDateString(),
            'effective_to' => $this->effectiveTo?->toDateString(),
            'is_active' => $this->isActive,
        ];
    }
}
