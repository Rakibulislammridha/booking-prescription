<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Patients\Enums\MedicationSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;

final readonly class MedicationData
{
    public function __construct(
        public string $genericName,
        public ?string $brandName = null,
        public ?int $genericId = null,
        public ?int $brandId = null,
        public ?int $customBrandId = null,
        public ?string $doseText = null,
        public MedicationSource $source = MedicationSource::Reported,
        public ?int $prescriptionItemId = null,
        public ?CarbonImmutable $startedOn = null,
        public ?CarbonImmutable $endedOn = null,
        public bool $isActive = true,
        public ?string $notes = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            genericName: trim((string) $v['generic_name']),
            brandName: isset($v['brand_name']) && trim((string) $v['brand_name']) !== '' ? trim((string) $v['brand_name']) : null,
            genericId: isset($v['generic_id']) ? (int) $v['generic_id'] : null,
            brandId: isset($v['brand_id']) ? (int) $v['brand_id'] : null,
            customBrandId: isset($v['custom_brand_id']) ? (int) $v['custom_brand_id'] : null,
            doseText: isset($v['dose_text']) && trim((string) $v['dose_text']) !== '' ? trim((string) $v['dose_text']) : null,
            source: isset($v['source']) ? MedicationSource::from((string) $v['source']) : MedicationSource::Reported,
            prescriptionItemId: isset($v['prescription_item_id']) ? (int) $v['prescription_item_id'] : null,
            startedOn: isset($v['started_on']) && $v['started_on'] !== '' ? CarbonImmutable::parse((string) $v['started_on'])->startOfDay() : null,
            endedOn: isset($v['ended_on']) && $v['ended_on'] !== '' ? CarbonImmutable::parse((string) $v['ended_on'])->startOfDay() : null,
            isActive: (bool) ($v['is_active'] ?? true),
            notes: isset($v['notes']) && trim((string) $v['notes']) !== '' ? trim((string) $v['notes']) : null,
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'generic_id' => $this->genericId,
            'brand_id' => $this->brandId,
            'custom_brand_id' => $this->customBrandId,
            'generic_name' => $this->genericName,
            'brand_name' => $this->brandName,
            'dose_text' => $this->doseText,
            'source' => $this->source,
            'prescription_item_id' => $this->prescriptionItemId,
            'started_on' => $this->startedOn,
            'ended_on' => $this->endedOn,
            'is_active' => $this->isActive,
            'notes' => $this->notes,
        ];
    }
}
