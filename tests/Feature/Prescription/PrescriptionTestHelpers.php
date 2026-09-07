<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Prescription\Actions\CreateDraftPrescription;
use App\Domain\Prescription\Actions\IssuePrescription;
use App\Domain\Prescription\Actions\SaveDraft;
use App\Domain\Prescription\Data\DraftPayload;
use App\Domain\Prescription\Data\IssueRequest;
use App\Domain\Shared\Actor;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\InvestigationCatalogItem;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Support\Facades\DB;

/**
 * Shared fixtures for the Prescription feature suite: catalog ids from the dev seed (CATALOG.md §3), a logged-in
 * doctor with an open visit, draft payload builders and the issue shortcut.
 */
trait PrescriptionTestHelpers
{
    protected function catalogGenericId(string $slug): int
    {
        return (int) DB::connection('catalog')->table('generics')->where('slug', $slug)->value('id');
    }

    /** @return array{generic_id: int, brand_id: int, strength_id: int} the first active presentation of a generic (optionally by form code / label) */
    protected function presentation(string $genericSlug, ?string $formCode = 'tab', ?string $labelLike = null): array
    {
        $c = DB::connection('catalog');
        $generic = $this->catalogGenericId($genericSlug);
        $q = $c->table('strengths')->join('dosage_forms', 'dosage_forms.id', '=', 'strengths.dosage_form_id')
            ->where('strengths.generic_id', $generic)->where('strengths.is_active', true)->orderBy('strengths.id');

        if ($formCode !== null) {
            $q->where('dosage_forms.code', $formCode);
        }

        if ($labelLike !== null) {
            $q->where('strengths.strength_label', 'ILIKE', "%{$labelLike}%");
        }

        $row = $q->first(['strengths.id as strength_id', 'strengths.brand_id', 'strengths.generic_id']);
        $this->assertNotNull($row, "no {$formCode} presentation for {$genericSlug}");

        return ['generic_id' => (int) $row->generic_id, 'brand_id' => (int) $row->brand_id, 'strength_id' => (int) $row->strength_id];
    }

    /**
     * Doctor logged in (guard web) with an open visit for a fresh adult patient.
     *
     * @param  array<string, mixed>  $patientAttributes
     * @return array{0: User, 1: Doctor, 2: Visit}
     */
    protected function doctorWithOpenVisit(array $patientAttributes = []): array
    {
        $user = $this->actingAsDoctor();
        $doctor = $user->doctor()->firstOrFail();
        $patient = Patient::factory()->create($patientAttributes + ['dob' => now()->subYears(34)->toDateString()]);
        $visit = Visit::factory()->urti()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);

        return [$user, $doctor, $visit];
    }

    protected function draftFor(Visit $visit, Doctor $doctor): Prescription
    {
        return app(CreateDraftPrescription::class)->handle($visit, $doctor, Actor::user(1));
    }

    /**
     * @param  list<array{slug: string, shorthand: string, form?: string, label?: string, overrides?: list<array{fingerprint: string, reason: string}>}>  $lines
     * @return list<array<string, mixed>>
     */
    protected function itemsPayload(array $lines): array
    {
        $items = [];

        foreach ($lines as $i => $line) {
            $items[] = ['key' => 'i'.($i + 1), 'sort_order' => $i, 'drug' => $this->presentation($line['slug'], $line['form'] ?? 'tab', $line['label'] ?? null), 'shorthand' => $line['shorthand'], 'safety_overrides' => $line['overrides'] ?? []];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $extra
     */
    protected function savedDraft(Prescription $rx, array $items, array $extra = []): Prescription
    {
        $payload = DraftPayload::fromArray(['items' => $items, 'investigations' => [], 'advice' => [], 'referrals' => []] + $extra);

        return app(SaveDraft::class)->handle($rx, $payload, Actor::user(1))->model;
    }

    /**
     * A fully populated issued prescription — vitals, complaints, a diagnosis, one Rx line, an investigation with a
     * price, bilingual advice and a follow-up — built through the real draft endpoint so the output tests render
     * exactly what the writer would have issued. `$padAttributes` overrides the doctor's pad before issue, which is
     * what freezes into pad_snapshot and therefore drives every bit of print geometry (§7.2).
     *
     * @param  array<string, mixed>  $padAttributes
     * @return array{0: Prescription, 1: Doctor, 2: Visit}
     */
    protected function issuedWithContent(array $padAttributes = [], string $patientName = 'Rehana Begum'): array
    {
        [, $doctor, $visit] = $this->doctorWithOpenVisit(['name' => $patientName]);

        if ($padAttributes !== []) {
            $doctor->padSetting()->update($padAttributes);
            $doctor->unsetRelation('padSetting');
        }

        Vital::factory()->for($visit)->create(['weight_kg' => 58, 'height_cm' => 160, 'bp_systolic' => 120, 'bp_diastolic' => 80]);
        $snippet = AdviceSnippet::factory()->create(['text' => 'Drink plenty of water', 'text_bn' => 'প্রচুর পানি পান করুন']);
        // investigation_catalog is unique on (branch, lower(name)), so a test that issues twice needs distinct names.
        $test = InvestigationCatalogItem::factory()->create(['name' => 'CBC with ESR #'.$visit->id, 'price_paisa' => 40000]);
        $draft = $this->draftFor($visit, $doctor);

        $this->patchJson('/panel/prescriptions/'.$draft->public_id.'/draft', [
            'language' => 'both',
            'items' => $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]),
            'investigations' => [['key' => 'x1', 'investigation_catalog_id' => $test->id, 'is_urgent' => false, 'sort_order' => 0]],
            'advice' => [['key' => 'a1', 'advice_snippet_id' => $snippet->id, 'sort_order' => 0]],
            'referrals' => [],
            'follow_up_days' => 7,
        ])->assertOk();

        return [$this->issued($draft->fresh()), $doctor, $visit];
    }

    protected function issued(Prescription $rx): Prescription
    {
        return app(IssuePrescription::class)->handle($rx, new IssueRequest, Actor::user((int) auth('web')->id()));
    }
}
