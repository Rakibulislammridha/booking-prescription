<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\FeeRule;

/** What FeeResolver decided and snapshots onto appointments (SCHEMA §5.10). */
final readonly class FeeDecision
{
    public function __construct(
        public AppointmentType $type,
        public FeeRule $rule,
        public int $listFeePaisa,
        public int $feePaisa,
        public ?string $reason,
        public ?int $previousAppointmentId = null,
        public ?string $previousVisitDate = null,
        public ?int $daysSincePrevious = null,
    ) {}

    /** @return array<string, mixed> the appointment columns */
    public function toColumns(): array
    {
        return [
            'type' => $this->type,
            'list_fee_paisa' => $this->listFeePaisa,
            'fee_paisa' => $this->feePaisa,
            'fee_rule' => $this->rule,
            'fee_rule_reason' => $this->reason,
        ];
    }
}
