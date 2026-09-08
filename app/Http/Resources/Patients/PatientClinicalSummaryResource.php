<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * PRESCRIPTION.md §1.1/§1.2 `PatientSummary` for the writer's left pane: name, age, sex, phone, code, family head,
 * active allergies / current conditions / active medications, the safety flags of §5.3 derived from coded
 * conditions, and the delivery-consent defaults of §8. Requires allergies, conditions, medications, consents,
 * primaryRelation.primary to be eager-loaded (one query per group, no N+1).
 *
 * @mixin Patient
 */
final class PatientClinicalSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $conditions = $this->conditions->filter(fn (PatientCondition $c) => in_array($c->status->value, ['active', 'chronic'], true))->values();
        $codes = $conditions->pluck('icd10_code')->filter()->map(fn (string $c) => strtoupper($c))->all();
        $head = $this->primaryRelation?->primary;

        return [
            'public_id' => $this->public_id,
            'patient_code' => $this->patient_code,
            'name' => $this->name,
            'age_years' => $this->age_years,
            'age_months' => $this->age_months,
            'age_text' => $this->age_text,
            'dob' => $this->dob?->toDateString(),
            'dob_is_estimated' => $this->dob_is_estimated,
            'sex' => $this->gender?->value,
            'phone' => $this->mobile,
            'blood_group' => $this->blood_group?->value,
            'preferred_language' => $this->preferred_language->value,
            'family_head' => $head === null ? null : ['public_id' => $head->public_id, 'name' => $head->name, 'relation' => $this->primaryRelation->relation->value],
            // Resolved, not left as resource collections: nested Responsables reach an Inertia page as
            // `{data: [...]}` (see PatientResource).
            'allergies' => AllergyResource::collection($this->allergies->where('is_active', true)->values())->resolve($request),
            'conditions' => ConditionResource::collection($conditions)->resolve($request),
            'medications' => MedicationResource::collection($this->medications->where('is_active', true)->values())->resolve($request),
            'flags' => [
                'pregnant' => self::any($codes, fn (string $c) => $c === 'Z33.1' || str_starts_with($c, 'O')),
                'lactating' => in_array('Z39.1', $codes, true),
                'renal' => self::any($codes, fn (string $c) => preg_match('/^N1[7-9]/', $c) === 1),
                'hepatic' => self::any($codes, fn (string $c) => preg_match('/^K7[0-7]/', $c) === 1 || str_starts_with($c, 'B18')),
            ],
            'consents' => $this->consentDefaults(),
            'last_visit_at' => $this->last_visit_at?->toIso8601ZuluString(),
            'visit_count' => $this->visit_count,
            'recent_visits' => [],   // placeholder until the Prescription module's visits exist (PRESCRIPTION §1.2)
        ];
    }

    /**
     * Latest row per type ∈ data_sharing, sms, whatsapp → granted?
     *
     * @return array<string, bool>
     */
    private function consentDefaults(): array
    {
        $latest = $this->consents
            ->sortByDesc(fn (PatientConsent $c) => [$c->occurred_at->getTimestamp(), $c->id])
            ->unique(fn (PatientConsent $c) => $c->type->value)
            ->keyBy(fn (PatientConsent $c) => $c->type->value);

        return collect([ConsentType::DataSharing, ConsentType::Sms, ConsentType::Whatsapp])
            ->mapWithKeys(fn (ConsentType $t) => [$t->value => ($latest->get($t->value)?->status) === ConsentStatus::Granted])
            ->all();
    }

    /**
     * @param  array<int, string>  $codes
     * @param  callable(string): bool  $test
     */
    private static function any(array $codes, callable $test): bool
    {
        foreach ($codes as $code) {
            if ($test($code)) {
                return true;
            }
        }

        return false;
    }
}
