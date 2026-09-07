<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Data\DraftPayload;
use App\Domain\Prescription\Data\DraftResult;
use App\Domain\Prescription\Data\VisitData;
use App\Domain\Prescription\Data\VitalsData;
use App\Domain\Prescription\Enums\PrescriptionLanguage;
use App\Domain\Prescription\Enums\SafetyStage;
use App\Domain\Prescription\Exceptions\DraftConflict;
use App\Domain\Prescription\Exceptions\PrescriptionNotDraft;
use App\Domain\Prescription\Services\DraftPersister;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Prescription\Services\ItemResolver;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Prescription\Services\SafetyChecker;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * PATCH …/draft (PRESCRIPTION.md §4.13): validates + re-parses every item (server authoritative; 422 on parse
 * errors), writes the visit keys, upserts child rows by key, stores overrides, runs SafetyPipeline::forDraft,
 * audits `update {event: draft_saved}` on Prescription (+ Visit when visit keys changed). 409 on a stale
 * expected_updated_at.
 */
final class SaveDraft
{
    public function __construct(
        private readonly ItemResolver $items,
        private readonly SafetyChecker $safety,
        private readonly DraftPersister $persister,
        private readonly DraftSerializer $serializer,
        private readonly UpdateVisit $updateVisit,
        private readonly UpdateVitals $updateVitals,
        private readonly PrescriptionAuditor $auditor,
    ) {}

    public function handle(Prescription $rx, DraftPayload $payload, Actor $actor): DraftResult
    {
        return DB::transaction(function () use ($rx, $payload, $actor): DraftResult {
            /** @var Prescription $rx */
            $rx = Prescription::query()->whereKey($rx->id)->lockForUpdate()->firstOrFail();
            $rx->load(['items', 'investigations', 'advice', 'referrals', 'visit.patient.allergies', 'visit.patient.conditions', 'visit.patient.medications', 'visit.latestVitals']);

            if (! $rx->isDraft()) {
                throw new PrescriptionNotDraft($rx->id, $rx->status->value);
            }

            if ($payload->expectedUpdatedAt !== null && $rx->updated_at !== null && ! CarbonImmutable::parse($payload->expectedUpdatedAt)->equalTo($rx->updated_at->startOfSecond())) {
                throw new DraftConflict($this->serializer->draft($rx));
            }

            $visit = $rx->visit;
            $prefs = (array) ($rx->doctor->profile->prefs ?? []);
            $contDays = (int) ($prefs['cont_days'] ?? config('prescription.cont_days', 30));
            $language = $payload->language ?? $rx->language->value;
            $before = ['language' => $rx->language->value, 'items' => $rx->items->count(), 'investigations' => $rx->investigations->count(), 'advice' => $rx->advice->count(), 'referrals' => $rx->referrals->count()];

            if ($payload->visit !== null || $payload->followUpDays !== null) {
                $visitData = $payload->visit ?? [];

                if ($payload->followUpDays !== null && empty($visitData['follow_up_on'])) {
                    $visitData['follow_up_on'] = Clock::today()->addDays($payload->followUpDays)->toDateString();
                }

                $this->updateVisit->handle($visit, VisitData::fromArray($visitData), $actor, 'draft_saved');
            }

            if ($payload->vitalsReviewed === true && $visit->latestVitals !== null) {
                $this->updateVitals->handle($visit->latestVitals, new VitalsData([], true), $actor);
            }

            $resolved = $payload->partial ? $this->items->fromRows($rx, $contDays, $language) : $this->items->resolve($payload->items, $rx->items->keyBy('id')->all(), $contDays, $language);
            $this->items->assertNoErrors($resolved);

            $rx->language = PrescriptionLanguage::from($language);
            $rx->save();

            $report = $this->safety->check($rx, $resolved, SafetyStage::Draft, null, $actor->userId);
            $keys = ['items' => $this->persister->items($rx, $resolved, $report, $actor, $language)];

            if (! $payload->partial) {
                $keys['investigations'] = $this->persister->investigations($rx, $payload->investigations);
                $keys['advice'] = $this->persister->advice($rx, $payload->advice);
                $keys['referrals'] = $this->persister->referrals($rx, $payload->referrals);
            }

            $rx->touch();
            $rx->unsetRelation('items')->unsetRelation('investigations')->unsetRelation('advice')->unsetRelation('referrals');
            $rx->load(['items', 'investigations', 'advice', 'referrals']);

            $after = ['language' => $rx->language->value, 'items' => $rx->items->count(), 'investigations' => $rx->investigations->count(), 'advice' => $rx->advice->count(), 'referrals' => $rx->referrals->count()];
            $this->auditor->draftSaved($rx, $before, $after);

            return new DraftResult($rx, $this->serializer->draft($rx, $report, $keys), $report);
        });
    }
}
