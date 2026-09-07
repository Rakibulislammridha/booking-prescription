<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Data;

use App\Domain\Scheduling\Enums\OverrideType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final readonly class ScheduleOverrideData
{
    public function __construct(
        public int $doctorId,
        public int $branchId,
        public CarbonImmutable $overrideDate,
        public OverrideType $type,
        public ?string $sessionCode = null,
        public ?int $delayMinutes = null,
        public ?string $newStartTime = null,
        public ?string $newEndTime = null,
        public ?int $newCounterQuota = null,
        public ?int $newOnlineQuota = null,
        public ?int $newBufferQuota = null,
        public ?string $reason = null,
        public bool $notifyPatients = true,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            doctorId: (int) $v['doctor_id'],
            branchId: (int) $v['branch_id'],
            overrideDate: CarbonImmutable::parse((string) $v['override_date'])->startOfDay(),
            type: OverrideType::from((string) $v['type']),
            sessionCode: isset($v['session_code']) && $v['session_code'] !== '' ? strtoupper((string) $v['session_code']) : null,
            delayMinutes: isset($v['delay_minutes']) ? (int) $v['delay_minutes'] : null,
            newStartTime: $v['new_start_time'] ?? null,
            newEndTime: $v['new_end_time'] ?? null,
            newCounterQuota: isset($v['new_counter_quota']) ? (int) $v['new_counter_quota'] : null,
            newOnlineQuota: isset($v['new_online_quota']) ? (int) $v['new_online_quota'] : null,
            newBufferQuota: isset($v['new_buffer_quota']) ? (int) $v['new_buffer_quota'] : null,
            reason: $v['reason'] ?? null,
            notifyPatients: (bool) ($v['notify_patients'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        $hasQuotas = $this->newCounterQuota !== null && $this->newOnlineQuota !== null && $this->newBufferQuota !== null;

        return [
            'doctor_id' => $this->doctorId,
            'branch_id' => $this->branchId,
            'override_date' => $this->overrideDate->toDateString(),
            'session_code' => $this->sessionCode,
            'type' => $this->type,
            'delay_minutes' => $this->delayMinutes,
            'new_start_time' => $this->newStartTime,
            'new_end_time' => $this->newEndTime,
            'new_max_serials' => $hasQuotas ? $this->newCounterQuota + $this->newOnlineQuota + $this->newBufferQuota : null,
            'new_online_quota' => $this->newOnlineQuota,
            'new_counter_quota' => $this->newCounterQuota,
            'new_buffer_quota' => $this->newBufferQuota,
            'reason' => $this->reason,
            'notify_patients' => $this->notifyPatients,
        ];
    }
}
