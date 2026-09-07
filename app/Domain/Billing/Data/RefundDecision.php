<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\RefundReason;

/** Whether money is owed back, under which reason code, and the sentence the desk shows. */
final readonly class RefundDecision
{
    public function __construct(
        public bool $eligible,
        public RefundReason $reasonCode,
        public ?int $minutesBeforeStart,
        public string $explanation,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'reason_code' => $this->reasonCode->value,
            'minutes_before_start' => $this->minutesBeforeStart,
            'explanation' => $this->explanation,
        ];
    }
}
