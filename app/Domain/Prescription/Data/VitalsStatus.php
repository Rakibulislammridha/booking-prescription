<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use Carbon\CarbonImmutable;

/**
 * "Has this encounter got vitals yet, and has the doctor seen them?" — the Prescription module's answer, in the
 * module's own words, for any surface that must not read `vitals` itself (VitalsStatusQuery, PRESCRIPTION.md §4.2).
 *
 * `readings` is the count of rows for the encounter (several sets per visit are allowed, §4.2), `recordedAt` the
 * most recent one, and `reviewedByDoctor` whether ANY of them carries `reviewed_by_doctor_at` — which is what the
 * desk means by "the doctor has it": one reviewed set is enough to know the handoff happened.
 */
final readonly class VitalsStatus
{
    public function __construct(
        public int $readings = 0,
        public ?CarbonImmutable $recordedAt = null,
        public bool $reviewedByDoctor = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function recorded(): bool
    {
        return $this->readings > 0;
    }

    /** @return array{recorded: bool, readings: int, recorded_at: string|null, reviewed: bool} */
    public function toArray(): array
    {
        return [
            'recorded' => $this->recorded(),
            'readings' => $this->readings,
            'recorded_at' => $this->recordedAt?->toIso8601ZuluString(),
            'reviewed' => $this->reviewedByDoctor,
        ];
    }
}
