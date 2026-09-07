<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Data\DraftResult;
use App\Domain\Prescription\Data\ResolvedItem;
use App\Domain\Prescription\Data\VisitData;
use App\Domain\Prescription\Enums\SafetyStage;
use App\Domain\Prescription\Exceptions\PrescriptionNotDraft;
use App\Domain\Prescription\Services\DraftPersister;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Prescription\Services\ItemResolver;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Prescription\Services\SafetyChecker;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionTemplate;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * PRESCRIPTION.md §3.6 apply {mode: append|replace}: items re-parsed from dose_json.normalized against today's
 * presentation (catalog refs re-validated; a vanished id yields a drug-less `drug_missing` line so the doctor
 * re-picks); duplicate generics already on the pad are skipped; follow-up = today + body.follow_up_days; the
 * template's diagnosis is added as provisional when absent. Audit `update {event: template_applied}`.
 */
final class ApplyTemplate
{
    public function __construct(
        private readonly ItemResolver $items,
        private readonly SafetyChecker $safety,
        private readonly DraftPersister $persister,
        private readonly DraftSerializer $serializer,
        private readonly UpdateVisit $updateVisit,
        private readonly PrescriptionAuditor $auditor,
    ) {}

    public function handle(Prescription $rx, PrescriptionTemplate $template, string $mode, Actor $actor): DraftResult
    {
        return DB::transaction(function () use ($rx, $template, $mode, $actor): DraftResult {
            /** @var Prescription $rx */
            $rx = Prescription::query()->whereKey($rx->id)->lockForUpdate()->firstOrFail();
            $rx->load(['items', 'investigations', 'advice', 'referrals', 'visit.patient.allergies', 'visit.patient.conditions', 'visit.patient.medications', 'visit.latestVitals', 'doctor.profile']);

            if (! $rx->isDraft()) {
                throw new PrescriptionNotDraft($rx->id, $rx->status->value);
            }

            $template->loadMissing('items');
            $visit = $rx->visit;
            $body = (array) $template->body;
            $prefs = (array) ($rx->doctor->profile->prefs ?? []);
            $contDays = (int) ($prefs['cont_days'] ?? config('prescription.cont_days', 30));
            $language = $rx->language->value;

            $rows = [];
            $onPad = [];

            if ($mode === 'append') {
                foreach ($rx->items as $item) {
                    $onPad[] = $item->generic_id;
                    $rows[] = ['key' => $item->clientKey(), 'id' => $item->id, 'shorthand' => (string) ($item->dose_json['raw'] ?? ''), 'safety_overrides' => $item->safety_overrides];
                }
            }

            $skipped = [];

            foreach ($template->items as $ti) {
                if ($ti->generic_id !== null && in_array($ti->generic_id, $onPad, true)) {
                    $skipped[] = $ti->generic_name;

                    continue;
                }

                $onPad[] = $ti->generic_id;
                $rows[] = [
                    'key' => 't'.$ti->id.'-'.count($rows),
                    'drug' => ['generic_id' => $ti->generic_id, 'brand_id' => $ti->brand_id, 'custom_brand_id' => $ti->custom_brand_id, 'strength_id' => $ti->strength_id],
                    'shorthand' => (string) ($ti->dose_json['normalized'] ?? $ti->dose_schedule ?? ''),
                    'instruction_bn' => $ti->instruction_bn,
                ];
            }

            $resolved = $this->items->resolve($rows, $rx->items->keyBy('id')->all(), $contDays, $language);

            // A template item whose catalog id vanished becomes a drug-less line (drug_missing) — never a 422 here (§3.6).
            $resolved = array_map(fn ($r) => $r->drug !== null && ! $r->drug->resolved ? new ResolvedItem($r->key, $r->id, $r->sortOrder, null, $r->shorthand, $this->items->resolve([['key' => $r->key, 'shorthand' => $r->shorthand]], [], $contDays, $language)[0]->parsed, [], $r->existingOverrides, $r->instructionBn) : $r, $resolved);

            $report = $this->safety->check($rx, $resolved, SafetyStage::Draft, null, $actor->userId);
            $keys = ['items' => $this->persister->items($rx, $resolved, $report, $actor, $language)];

            $investigations = $mode === 'append' ? $rx->investigations->map(fn ($x) => $this->serializer->investigation($x))->all() : [];
            $advice = $mode === 'append' ? $rx->advice->map(fn ($a) => $this->serializer->advice($a))->all() : [];

            foreach ((array) ($body['investigations'] ?? []) as $x) {
                $investigations[] = ['investigation_catalog_id' => $x['investigation_catalog_id'] ?? null, 'name' => $x['name'] ?? ''];
            }

            foreach ((array) ($body['advice'] ?? []) as $a) {
                $advice[] = ['advice_snippet_id' => $a['snippet_id'] ?? null, 'text' => $a['text'] ?? '', 'text_bn' => $a['text_bn'] ?? null];
            }

            $keys['investigations'] = $this->persister->investigations($rx, array_values($investigations));
            $keys['advice'] = $this->persister->advice($rx, array_values($advice));

            $visitData = [];

            if (($body['follow_up_days'] ?? null) !== null) {
                $visitData['follow_up_on'] = Clock::today()->addDays((int) $body['follow_up_days'])->toDateString();
            }

            if ($template->icd10_code !== null && ! in_array($template->icd10_code, $visit->diagnosisCodes(), true)) {
                $visitData['diagnoses'] = [...$visit->diagnoses, ['icd10_code' => $template->icd10_code, 'title' => $template->diagnosis_title ?? $template->icd10_code, 'kind' => 'provisional', 'sort' => count($visit->diagnoses)]];
            }

            if ($visit->chief_complaints === [] && ($body['chief_complaints'] ?? []) !== []) {
                $visitData['chief_complaints'] = $body['chief_complaints'];
            }

            if ($visit->examination_findings === null && ! empty($body['examination_findings'])) {
                $visitData['examination_findings'] = $body['examination_findings'];
            }

            if ($visitData !== []) {
                $this->updateVisit->handle($visit, VisitData::fromArray($visitData), $actor, 'template_applied');
            }

            $template->forceFill(['use_count' => $template->use_count + 1, 'last_used_at' => now()])->save();
            $this->auditor->templateApplied($rx, $template->id, $mode);

            $rx->touch();
            $rx->unsetRelation('items')->unsetRelation('investigations')->unsetRelation('advice');
            $rx->load(['items', 'investigations', 'advice', 'referrals']);

            $draft = $this->serializer->draft($rx, $report, $keys);
            $draft['template_skipped'] = $skipped;

            return new DraftResult($rx, $draft, $report);
        });
    }
}
