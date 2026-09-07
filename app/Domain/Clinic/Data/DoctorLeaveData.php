<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use App\Domain\Clinic\Enums\LeaveType;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final readonly class DoctorLeaveData
{
    public function __construct(
        public int $doctorId,
        public CarbonImmutable $startsOn,
        public CarbonImmutable $endsOn,
        public LeaveType $type = LeaveType::Planned,
        public ?int $branchId = null,
        public ?string $reason = null,
        public bool $notifyPatients = true,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            doctorId: (int) $v['doctor_id'],
            startsOn: CarbonImmutable::parse((string) $v['starts_on'])->startOfDay(),
            endsOn: CarbonImmutable::parse((string) $v['ends_on'])->startOfDay(),
            type: LeaveType::from((string) ($v['type'] ?? LeaveType::Planned->value)),
            branchId: isset($v['branch_id']) ? (int) $v['branch_id'] : null,
            reason: $v['reason'] ?? null,
            notifyPatients: (bool) ($v['notify_patients'] ?? true),
        );
    }
}
