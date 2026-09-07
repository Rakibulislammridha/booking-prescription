<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/** POST/PUT /panel/prescription-templates body (PRESCRIPTION.md §3.6). */
final readonly class TemplateData
{
    /**
     * @param  array<string, mixed>|null  $body  {chief_complaints, examination_findings, advice[], investigations[], follow_up_days}
     * @param  list<array<string, mixed>>|null  $items  explicit template items (drug{…} + shorthand) — null = keep / capture from prescription
     */
    public function __construct(
        public string $name,
        public ?string $shorthand = null,
        public ?string $icd10Code = null,
        public ?string $diagnosisTitle = null,
        public bool $isShared = false,
        public ?string $fromPrescriptionId = null,
        public ?array $body = null,
        public ?array $items = null,
        public bool $includeClinical = true,
    ) {}

    /** @param  array<string, mixed>  $v */
    public static function fromArray(array $v): self
    {
        return new self(
            name: trim((string) $v['name']),
            shorthand: isset($v['shorthand']) && trim((string) $v['shorthand']) !== '' ? '/'.ltrim(trim((string) $v['shorthand']), '/') : null,
            icd10Code: isset($v['icd10_code']) && $v['icd10_code'] !== '' ? strtoupper((string) $v['icd10_code']) : null,
            diagnosisTitle: isset($v['diagnosis_title']) && trim((string) $v['diagnosis_title']) !== '' ? trim((string) $v['diagnosis_title']) : null,
            isShared: (bool) ($v['is_shared'] ?? false),
            fromPrescriptionId: isset($v['from_prescription_id']) ? (string) $v['from_prescription_id'] : null,
            body: isset($v['body']) && is_array($v['body']) ? $v['body'] : null,
            items: isset($v['items']) && is_array($v['items']) ? array_values($v['items']) : null,
            includeClinical: (bool) ($v['include_clinical'] ?? true),
        );
    }
}
