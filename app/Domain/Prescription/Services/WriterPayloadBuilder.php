<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Patients\Services\MobileNumber;
use App\Domain\Prescription\Shorthand\Keywords;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\ExternalDiagnosticCentre;
use App\Models\Tenant\InvestigationCatalogItem;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\Visit;
use App\Tenancy\Facades\Tenancy;
use Laravel\Pennant\Feature;

/**
 * WriterPageProps (PRESCRIPTION.md §1.2): everything the doctor needs before the first keystroke, one query per prop
 * group (eager loads; top-50 from the learning cache).
 */
final class WriterPayloadBuilder
{
    public function __construct(private readonly DraftSerializer $serializer, private readonly DoctorLearningCache $learning) {}

    /** @return array<string, mixed> */
    public function build(Visit $visit, Prescription $draft, Doctor $doctor, ?int $branchId): array
    {
        $visit->loadMissing(['patient.allergies', 'patient.conditions', 'patient.medications', 'serial', 'sessionInstance', 'latestVitals.recordedBy']);
        $doctor->loadMissing(['profile', 'padSetting']);
        $pad = $doctor->padSetting ?? new DoctorPadSetting(DoctorPadSetting::defaults());
        $prefs = (array) ($doctor->profile->prefs ?? []);

        return [
            'visit' => $this->serializer->visit($visit),
            'patient' => $this->patient($visit->patient),
            'vitals' => $visit->latestVitals !== null ? $this->serializer->vitals($visit->latestVitals) : null,
            'recent_visits' => $this->recentVisits($visit),
            'prescription' => $this->serializer->draft($draft),
            'doctor' => [
                'id' => $doctor->id, 'public_id' => $doctor->public_id, 'name' => $doctor->name,
                'pad' => [
                    'paper_size' => $pad->paper_size->value, 'letterhead_enabled' => $pad->letterhead_enabled, 'preprinted_mode' => $pad->preprinted_mode,
                    'default_language' => (string) $pad->default_language, 'show_qr' => $pad->show_qr, 'show_vitals' => $pad->show_vitals,
                    'layout' => $pad->layout,
                ],
                'prefs' => [
                    'default_duration_days' => (int) ($prefs['default_duration_days'] ?? 5),
                    'cont_days' => (int) ($prefs['cont_days'] ?? config('prescription.cont_days', 30)),
                    'dictation_lang' => (string) ($prefs['dictation_lang'] ?? 'bn-BD'),
                    'cheatsheet_seen_count' => (int) ($prefs['cheatsheet_seen_count'] ?? 0),
                ],
            ],
            'quick_pick' => [
                'top_drugs' => $this->learning->top50($doctor->id),
                'templates' => PrescriptionTemplate::query()->visibleTo($doctor->id)->withCount('items')->orderBy('name')->get()
                    ->map(fn (PrescriptionTemplate $t) => self::templateBrief($t))->values()->all(),
                'snippets' => AdviceSnippet::query()->active()->visibleTo($doctor->id)->orderByDesc('use_count')->orderBy('id')->get()
                    ->map(fn (AdviceSnippet $s) => self::snippetRow($s))->values()->all(),
                'investigations' => InvestigationCatalogItem::query()->active()->forBranch($branchId)->orderBy('sort_order')->orderBy('name')->get()
                    ->map(fn (InvestigationCatalogItem $i) => self::investigationRow($i))->values()->all(),
                'external_centres' => ExternalDiagnosticCentre::query()->active()->orderBy('name')->get()
                    ->map(fn (ExternalDiagnosticCentre $c) => ['id' => $c->id, 'name' => $c->name, 'address' => $c->address, 'phone' => $c->phone])->values()->all(),
            ],
            'features' => [
                'ai' => $this->aiEnabled(),
                'voice' => (bool) config('prescription.features.voice', true),
                'handwriting' => (bool) config('prescription.features.handwriting', true),
                'drawing_backgrounds' => (array) config('prescription.drawing_backgrounds', ['blank', 'dental_adult', 'dental_child', 'eye_pair', 'skeleton_front', 'body_front_back', 'spine', 'abdomen']),
            ],
            'cheat_sheet_version' => Keywords::version(),
        ];
    }

    /**
     * PatientSummary for the left pane (PRESCRIPTION.md §8.1).
     *
     * @return array<string, mixed>
     */
    public function patient(Patient $patient): array
    {
        $codes = $patient->conditions->filter(fn ($c) => in_array($c->status->value, ['active', 'chronic'], true))->map(fn ($c) => strtoupper((string) $c->icd10_code));

        return [
            'public_id' => $patient->public_id, 'patient_code' => $patient->patient_code, 'name' => $patient->name, 'age_text' => $patient->age_text,
            'age_years' => $patient->age_years, 'age_months' => $patient->age_months, 'sex' => $patient->gender?->value, 'phone' => $patient->mobile_local,
            'mobile' => $patient->mobile, 'mobile_masked' => MobileNumber::mask($patient->mobile), 'blood_group' => $patient->blood_group?->value, 'dob' => $patient->dob?->toDateString(),
            'family_head' => $patient->is_mobile_owner ? null : $patient->primaryRelation?->primary?->name,
            'allergies' => $patient->allergies->filter(fn ($a) => $a->is_active)->map(fn ($a) => [
                'id' => $a->id, 'allergen_type' => $a->allergen_type->value, 'allergen_name' => $a->allergen_name, 'generic_id' => $a->generic_id,
                'allergy_class_id' => $a->allergy_class_id, 'reaction' => $a->reaction, 'severity' => $a->severity->value,
            ])->values()->all(),
            'conditions' => $patient->conditions->map(fn ($c) => ['id' => $c->id, 'icd10_code' => $c->icd10_code, 'condition_name' => $c->condition_name, 'status' => $c->status->value, 'onset_date' => $c->onset_date?->toDateString()])->values()->all(),
            'medications' => $patient->medications->filter(fn ($m) => $m->is_active)->map(fn ($m) => ['id' => $m->id, 'generic_id' => $m->generic_id, 'generic_name' => $m->generic_name, 'brand_name' => $m->brand_name, 'dose_text' => $m->dose_text, 'source' => $m->source->value])->values()->all(),
            'flags' => [
                'pregnant' => $codes->contains(fn ($c) => $c === 'Z33.1' || str_starts_with($c, 'O')),
                'lactating' => $codes->contains('Z39.1'),
                'renal' => $codes->contains(fn ($c) => preg_match('/^N1[7-9]/', $c) === 1),
                'hepatic' => $codes->contains(fn ($c) => preg_match('/^(K7[0-7]|B18)/', $c) === 1),
            ],
        ];
    }

    /**
     * Last 5 visits of the patient excluding this one (VisitBrief).
     *
     * @return list<array<string, mixed>>
     */
    public function recentVisits(Visit $visit, int $limit = 5): array
    {
        return Visit::query()->where('patient_id', $visit->patient_id)->whereKeyNot($visit->id)
            ->with(['doctor:id,name', 'currentPrescription:id,public_id,status,version,issued_at'])->withCount(['prescriptions as rx_item_count' => fn ($q) => $q->whereNull('id')])
            ->orderByDesc('started_at')->limit($limit)->get()
            ->map(function (Visit $v): array {
                $rx = $v->currentPrescription;
                $itemCount = $rx !== null ? $rx->items()->count() : 0;

                return [
                    'id' => $v->public_id, 'date' => $v->started_at->toDateString(), 'doctor' => $v->doctor->name,
                    'dx' => array_values(array_map(fn ($d) => (string) ($d['title'] ?? $d['icd10_code'] ?? ''), $v->diagnoses)),
                    'rx_item_count' => $itemCount, 'follow_up_on' => $v->follow_up_on?->toDateString(),
                    'prescription_id' => $rx !== null && ! $rx->isDraft() ? $rx->public_id : null, 'prescription_status' => $rx?->status->value,
                ];
            })->values()->all();
    }

    /** @return array<string, mixed> */
    public static function templateBrief(PrescriptionTemplate $t): array
    {
        return [
            'id' => $t->id, 'name' => $t->name, 'shorthand' => $t->shorthand, 'icd10_code' => $t->icd10_code, 'diagnosis_title' => $t->diagnosis_title,
            'item_count' => (int) ($t->items_count ?? $t->items()->count()), 'follow_up_days' => $t->body['follow_up_days'] ?? null,
            'is_shared' => $t->is_shared, 'doctor_id' => $t->doctor_id, 'use_count' => $t->use_count,
        ];
    }

    /** @return array<string, mixed> */
    public static function snippetRow(AdviceSnippet $s): array
    {
        return ['id' => $s->id, 'doctor_id' => $s->doctor_id, 'shorthand' => $s->shorthand, 'category' => $s->category?->value, 'text' => $s->text, 'text_bn' => $s->text_bn, 'is_shared' => $s->is_shared, 'use_count' => $s->use_count, 'is_active' => $s->is_active];
    }

    /** @return array<string, mixed> */
    public static function investigationRow(InvestigationCatalogItem $i): array
    {
        return ['id' => $i->id, 'branch_id' => $i->branch_id, 'code' => $i->code, 'name' => $i->name, 'name_bn' => $i->name_bn, 'category' => $i->category->value, 'price_paisa' => $i->price_paisa, 'prep_instructions' => $i->prep_instructions, 'prep_instructions_bn' => $i->prep_instructions_bn, 'is_active' => $i->is_active, 'sort_order' => $i->sort_order];
    }

    private function aiEnabled(): bool
    {
        $tenant = Tenancy::current();

        if ($tenant === null || (string) config('services.ai.key', '') === '') {
            return false;
        }

        try {
            return (bool) Feature::for($tenant)->active('ai-assist');
        } catch (\Throwable) {
            return false;
        }
    }
}
