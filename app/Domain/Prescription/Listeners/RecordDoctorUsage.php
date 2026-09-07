<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Listeners;

use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\DoctorDrugUsage;
use App\Models\Tenant\DoctorFavourite;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * PRESCRIPTION.md §3.5 per-doctor learning on PrescriptionIssued (queued, `default`, TenantAware, idempotent per
 * prescription): doctor_drug_usage monthly rows, doctor_favourites (global + per diagnosis; pinned rows never
 * overwritten), ICD usage, advice_snippets.use_count; then the top-50 / usage / fav caches are refreshed.
 */
final class RecordDoctorUsage implements ShouldQueue
{
    use InteractsWithQueue, TenantAware;

    public string $queue = 'default';

    public int $tries = 3;

    public function __construct(private readonly DoctorLearningCache $cache)
    {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(PrescriptionIssued $event): void
    {
        $rx = Prescription::query()->with(['items', 'advice', 'visit'])->find($event->prescriptionId);

        if ($rx === null || $rx->isDraft() || $rx->issued_at === null) {
            return;
        }

        $visit = $rx->visit;
        $primary = $visit->primaryDiagnosisCode();
        $codes = $visit->diagnosisCodes();
        $month = Clock::today()->startOfMonth();
        $issuedAt = $rx->issued_at;

        foreach ($rx->items as $item) {
            if ($item->generic_id === null) {
                continue;
            }

            $dose = self::doseOf($item);

            $usage = DoctorDrugUsage::query()->where('doctor_id', $rx->doctor_id)->where('generic_id', $item->generic_id)->where('period_month', $month->toDateString())
                ->where(fn ($q) => $primary === null ? $q->whereNull('icd10_code') : $q->where('icd10_code', $primary))
                ->where(fn ($q) => $item->brand_id === null ? $q->whereNull('brand_id') : $q->where('brand_id', $item->brand_id))
                ->where(fn ($q) => $item->custom_brand_id === null ? $q->whereNull('custom_brand_id') : $q->where('custom_brand_id', $item->custom_brand_id))
                ->where(fn ($q) => $item->strength_id === null ? $q->whereNull('strength_id') : $q->where('strength_id', $item->strength_id))
                ->first();

            if ($usage !== null && $usage->last_used_at->greaterThanOrEqualTo($issuedAt)) {
                continue;                                             // already recorded for this issue (idempotent replay)
            }

            $usage ??= new DoctorDrugUsage(['doctor_id' => $rx->doctor_id, 'icd10_code' => $primary, 'generic_id' => $item->generic_id, 'brand_id' => $item->brand_id, 'custom_brand_id' => $item->custom_brand_id, 'strength_id' => $item->strength_id, 'period_month' => $month->toDateString(), 'use_count' => 0]);
            $usage->fill(['use_count' => $usage->use_count + 1, 'last_dose' => $dose, 'last_used_at' => $issuedAt]);
            $usage->save();

            foreach (array_unique([null, ...$codes]) as $code) {
                $this->learnFavourite($rx->doctor_id, $code, $item, $dose, $issuedAt);
            }
        }

        foreach ($codes as $code) {
            $this->cache->bumpIcd($rx->doctor_id, $code);
        }

        foreach ($rx->advice as $advice) {
            if ($advice->advice_snippet_id !== null) {
                $snippet = AdviceSnippet::query()->find($advice->advice_snippet_id);
                $snippet?->forceFill(['use_count' => $snippet->use_count + 1])->save();
            }
        }

        $this->cache->refresh($rx->doctor_id, $codes);
    }

    /** @param  array<string, mixed>  $dose */
    private function learnFavourite(int $doctorId, ?string $code, PrescriptionItem $item, array $dose, \DateTimeInterface $issuedAt): void
    {
        $fav = DoctorFavourite::query()->where('doctor_id', $doctorId)->where('generic_id', $item->generic_id)
            ->where(fn ($q) => $code === null ? $q->whereNull('icd10_code') : $q->where('icd10_code', $code))
            ->where(fn ($q) => $item->brand_id === null ? $q->whereNull('brand_id') : $q->where('brand_id', $item->brand_id))
            ->where(fn ($q) => $item->custom_brand_id === null ? $q->whereNull('custom_brand_id') : $q->where('custom_brand_id', $item->custom_brand_id))
            ->first();

        if ($fav !== null && $fav->last_used_at !== null && $fav->last_used_at->greaterThanOrEqualTo($issuedAt)) {
            return;
        }

        $fav ??= new DoctorFavourite(['doctor_id' => $doctorId, 'icd10_code' => $code, 'generic_id' => $item->generic_id, 'brand_id' => $item->brand_id, 'custom_brand_id' => $item->custom_brand_id, 'use_count' => 0, 'rank' => 0, 'is_pinned' => false]);
        $attributes = ['use_count' => $fav->use_count + 1, 'last_used_at' => $issuedAt];

        if (! $fav->is_pinned) {
            $attributes += ['strength_id' => $item->strength_id, 'label' => self::labelOf($item), 'default_dose' => $dose];
        }

        $fav->fill($attributes)->save();
    }

    /** @return array<string, mixed> */
    public static function doseOf(PrescriptionItem $item): array
    {
        return ['dose_schedule' => $item->dose_schedule, 'duration_days' => $item->duration_days, 'timing' => $item->timing->value, 'instruction' => $item->instruction, 'shorthand' => (string) ($item->dose_json['normalized'] ?? '')];
    }

    public static function labelOf(PrescriptionItem $item): string
    {
        return $item->brand_name === null ? $item->generic_name.' (any brand)' : trim($item->brand_name.' '.($item->strength ?? '').' '.($item->form ?? ''));
    }
}
