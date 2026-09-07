<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

/** The frozen commission of one invoice line. doctorPaisa + clinicPaisa always equals the line total. */
final readonly class RevenueSplit
{
    public function __construct(
        public ?int $ruleId,
        public int $doctorPaisa,
        public int $clinicPaisa,
    ) {}

    /** @return array<string, int|null> the invoice_items columns */
    public function toColumns(): array
    {
        return [
            'doctor_revenue_share_id' => $this->ruleId,
            'doctor_share_paisa' => $this->doctorPaisa,
            'clinic_share_paisa' => $this->clinicPaisa,
        ];
    }
}
