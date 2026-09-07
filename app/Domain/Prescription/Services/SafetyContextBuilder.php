<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Patients\Enums\AllergenType;
use App\Domain\Prescription\Data\PatientSafetyProfile;
use App\Domain\Prescription\Data\ResolvedItem;
use App\Domain\Prescription\Data\SafetyItem;
use App\Domain\Prescription\Enums\SafetyStage;
use App\Domain\Prescription\Safety\DailyDoseCalculator;
use App\Domain\Prescription\Safety\SafetyContext;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientMedication;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use App\Support\Clock;

/**
 * Builds the SafetyContext (PRESCRIPTION.md §5.1) from tenant rows: the de-identified PatientSafetyProfile
 * (conditions → pregnancy / lactation / renal / hepatic flags, allergies, active medications, weight from the
 * latest vitals) and one SafetyItem per resolved line (generic ids only, daily mg via DailyDoseCalculator).
 */
final class SafetyContextBuilder
{
    public function __construct(private readonly DrugRefResolver $drugs) {}

    /**
     * @param  list<ResolvedItem>  $items
     * @param  array<string, array{reason: string, by: int|null, at: string|null}>  $overrides
     */
    public function build(int $prescriptionId, SafetyStage $stage, Visit $visit, array $items, array $overrides): SafetyContext
    {
        return new SafetyContext($prescriptionId, $stage, $this->profile($visit), $this->items($items), $overrides);
    }

    public function profile(Visit $visit): PatientSafetyProfile
    {
        /** @var Patient $patient */
        $patient = $visit->relationLoaded('patient') ? $visit->patient : $visit->patient()->firstOrFail();
        $conditions = $patient->relationLoaded('conditions') ? $patient->conditions : $patient->conditions()->get();
        $allergies = $patient->relationLoaded('allergies') ? $patient->allergies : $patient->allergies()->get();
        $medications = $patient->relationLoaded('medications') ? $patient->medications : $patient->medications()->get();

        $current = $conditions->filter(fn (PatientCondition $c) => in_array($c->status->value, ['active', 'chronic'], true));
        $codes = $current->map(fn (PatientCondition $c) => strtoupper((string) $c->icd10_code))->filter()->values();

        $pregnancy = $current->first(fn (PatientCondition $c) => $c->icd10_code !== null && (strtoupper($c->icd10_code) === 'Z33.1' || str_starts_with(strtoupper($c->icd10_code), 'O')));
        $trimester = null;

        if ($pregnancy?->onset_date !== null) {
            $weeks = (int) $pregnancy->onset_date->diffInWeeks(Clock::today());
            $trimester = $weeks <= 13 ? 1 : ($weeks <= 27 ? 2 : 3);
        }

        $allergyGenerics = [];
        $allergyClasses = [];
        $texts = [];
        $severities = [];

        foreach ($allergies->filter(fn (PatientAllergy $a) => $a->is_active) as $a) {
            $severity = $a->severity->value;

            if ($a->allergen_type === AllergenType::Generic && $a->generic_id !== null) {
                $allergyGenerics[] = $a->generic_id;
                $severities[$a->generic_id] = $severity;
            } elseif ($a->allergen_type === AllergenType::AllergyClass && $a->allergy_class_id !== null) {
                $allergyClasses[] = $a->allergy_class_id;
                $severities[$a->allergy_class_id] = $severity;
            } else {
                $texts[] = ['name' => $a->allergen_name, 'severity' => $severity, 'reaction' => $a->reaction];
            }
        }

        $medIds = [];
        $medNames = [];

        foreach ($medications->filter(fn (PatientMedication $m) => $m->is_active && $m->generic_id !== null) as $m) {
            $medIds[] = $m->generic_id;
            $medNames[$m->generic_id] = $m->brand_name !== null ? "{$m->brand_name} ({$m->generic_name})" : $m->generic_name;
        }

        $vitals = $visit->relationLoaded('latestVitals') ? $visit->latestVitals : $visit->latestVitals()->first();
        $weight = ($vitals !== null ? $vitals->weight_kg : null) ?? Vital::query()->where('patient_id', $patient->id)->whereNotNull('weight_kg')->orderByDesc('recorded_at')->value('weight_kg');

        return new PatientSafetyProfile(
            ageMonths: $patient->age_months,
            weightKg: $weight === null ? null : (float) $weight,
            sex: $patient->gender?->value,
            isPregnant: $pregnancy !== null,
            isLactating: $codes->contains('Z39.1'),
            trimester: $trimester,
            renalImpairment: $codes->contains(fn ($c) => preg_match('/^N1[7-9]/', $c) === 1),
            hepaticImpairment: $codes->contains(fn ($c) => preg_match('/^(K7[0-7]|B18)/', $c) === 1),
            allergyGenericIds: array_values(array_unique($allergyGenerics)),
            allergyClassIds: array_values(array_unique($allergyClasses)),
            allergyTexts: $texts,
            allergySeverities: $severities,
            currentMedicationGenericIds: array_values(array_unique($medIds)),
            currentMedicationNames: $medNames,
            pregnancyStatusKnown: $pregnancy !== null || $codes->contains('Z32.02'),
        );
    }

    /**
     * @param  list<ResolvedItem>  $items
     * @return list<SafetyItem>
     */
    public function items(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            $drug = $item->drug;
            $parsed = $item->parsed->hasErrors() ? null : $item->parsed;

            $out[] = new SafetyItem(
                key: $item->key,
                genericId: $drug?->genericId,
                brandId: $drug?->brandId,
                customBrandId: $drug?->customBrandId,
                strengthId: $drug?->strengthId,
                strengthMg: $drug?->strengthMg,
                perMl: $drug?->perMl,
                formCode: $drug?->formCode,
                routeCode: ($parsed !== null ? $parsed->routeCode : null) ?? $drug?->routeCode,
                routeId: $drug?->routeId,
                doseJson: $parsed,
                dailyMg: DailyDoseCalculator::dailyMg($parsed, $drug?->strengthMg, $drug?->perMl, $drug?->formCode),
                perDoseMg: DailyDoseCalculator::perDoseMg($parsed, $drug?->strengthMg, $drug?->perMl, $drug?->formCode),
                genericName: $drug !== null ? $drug->genericName : '',
                brandName: $drug?->brandName,
                isSystemic: $drug === null || $drug->isSystemic,
                customBrand: $this->drugs->customBrandFacts($drug?->customBrandId),
                hasParseErrors: $item->parsed->hasErrors(),
            );
        }

        return $out;
    }

    /**
     * Stored per-item overrides → fingerprint map (the pipeline's input).
     *
     * @param  list<ResolvedItem>  $items
     * @param  int|null  $userId  who is overriding right now (requests carry no by/at yet)
     * @return array<string, array{reason: string, by: int|null, at: string|null}>
     */
    public static function overridesFrom(array $items, ?int $userId = null): array
    {
        $map = [];

        foreach ($items as $item) {
            foreach ($item->existingOverrides as $o) {
                if (isset($o['fingerprint'])) {
                    $map[(string) $o['fingerprint']] = ['reason' => (string) ($o['reason'] ?? ''), 'by' => isset($o['overridden_by_user_id']) ? (int) $o['overridden_by_user_id'] : null, 'at' => isset($o['overridden_at']) ? (string) $o['overridden_at'] : null];
                }
            }

            foreach ($item->overrideRequests as $o) {
                if ($o['fingerprint'] !== '' && ! isset($map[$o['fingerprint']])) {
                    $map[$o['fingerprint']] = ['reason' => $o['reason'], 'by' => $userId, 'at' => $userId !== null ? now()->toIso8601String() : null];
                }
            }
        }

        return $map;
    }
}
