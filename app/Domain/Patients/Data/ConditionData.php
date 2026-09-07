<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Patients\Enums\ConditionStatus;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final readonly class ConditionData
{
    public function __construct(
        public string $conditionName,
        public ?string $icd10Code = null,
        public ConditionStatus $status = ConditionStatus::Active,
        public ?CarbonImmutable $onsetDate = null,
        public ?CarbonImmutable $resolvedDate = null,
        public ?string $notes = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            conditionName: trim((string) $v['condition_name']),
            icd10Code: isset($v['icd10_code']) && trim((string) $v['icd10_code']) !== '' ? strtoupper(trim((string) $v['icd10_code'])) : null,
            status: isset($v['status']) ? ConditionStatus::from((string) $v['status']) : ConditionStatus::Active,
            onsetDate: isset($v['onset_date']) && $v['onset_date'] !== '' ? CarbonImmutable::parse((string) $v['onset_date'])->startOfDay() : null,
            resolvedDate: isset($v['resolved_date']) && $v['resolved_date'] !== '' ? CarbonImmutable::parse((string) $v['resolved_date'])->startOfDay() : null,
            notes: isset($v['notes']) && trim((string) $v['notes']) !== '' ? trim((string) $v['notes']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'icd10_code' => $this->icd10Code,
            'condition_name' => $this->conditionName,
            'status' => $this->status,
            'onset_date' => $this->onsetDate,
            'resolved_date' => $this->status === ConditionStatus::Resolved ? ($this->resolvedDate ?? CarbonImmutable::now()->startOfDay()) : $this->resolvedDate,
            'notes' => $this->notes,
        ];
    }
}
