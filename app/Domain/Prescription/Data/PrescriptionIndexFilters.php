<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use App\Domain\Prescription\Enums\PrescriptionStatus;

/**
 * The prescriptions index's filter bar as the query sees it (IndexPrescriptionsRequest::toData()). `from` / `to`
 * are Dhaka calendar days (`Y-m-d`); `doctor` is a doctor public id. Echoed back to the page verbatim so the bar
 * shows what the list was actually filtered by.
 */
final readonly class PrescriptionIndexFilters
{
    public function __construct(
        public string $q = '',
        public ?string $doctor = null,
        public ?PrescriptionStatus $status = null,
        public ?string $from = null,
        public ?string $to = null,
        public int $page = 1,
    ) {}

    /** @return array{q: string, doctor: string|null, status: string|null, from: string|null, to: string|null} */
    public function toArray(): array
    {
        return ['q' => $this->q, 'doctor' => $this->doctor, 'status' => $this->status?->value, 'from' => $this->from, 'to' => $this->to];
    }
}
