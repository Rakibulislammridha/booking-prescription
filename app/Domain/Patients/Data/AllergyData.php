<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use App\Domain\Patients\Enums\AllergenType;
use App\Domain\Patients\Enums\AllergySeverity;
use Illuminate\Foundation\Http\FormRequest;

final readonly class AllergyData
{
    public function __construct(
        public AllergenType $allergenType,
        public string $allergenName,
        public ?int $genericId = null,
        public ?int $allergyClassId = null,
        public ?string $reaction = null,
        public AllergySeverity $severity = AllergySeverity::Unknown,
        public ?string $notes = null,
        public bool $isActive = true,
        public ?int $verifiedByDoctorId = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();
        $type = AllergenType::from((string) $v['allergen_type']);

        return new self(
            allergenType: $type,
            allergenName: trim((string) $v['allergen_name']),
            genericId: $type === AllergenType::Generic && isset($v['generic_id']) ? (int) $v['generic_id'] : null,
            allergyClassId: $type === AllergenType::AllergyClass && isset($v['allergy_class_id']) ? (int) $v['allergy_class_id'] : null,
            reaction: isset($v['reaction']) && trim((string) $v['reaction']) !== '' ? trim((string) $v['reaction']) : null,
            severity: isset($v['severity']) ? AllergySeverity::from((string) $v['severity']) : AllergySeverity::Unknown,
            notes: isset($v['notes']) && trim((string) $v['notes']) !== '' ? trim((string) $v['notes']) : null,
            isActive: (bool) ($v['is_active'] ?? true),
            verifiedByDoctorId: isset($v['verified_by_doctor_id']) ? (int) $v['verified_by_doctor_id'] : null,
        );
    }

    /** @return array<string, mixed> */
    public function toAttributes(): array
    {
        return [
            'allergen_type' => $this->allergenType,
            'generic_id' => $this->genericId,
            'allergy_class_id' => $this->allergyClassId,
            'allergen_name' => $this->allergenName,
            'reaction' => $this->reaction,
            'severity' => $this->severity,
            'notes' => $this->notes,
            'is_active' => $this->isActive,
            'verified_by_doctor_id' => $this->verifiedByDoctorId,
        ];
    }
}
