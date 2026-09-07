<?php

declare(strict_types=1);

namespace App\Domain\Prescription\AI;

use App\Models\Tenant\Visit;
use App\Support\Clock;

/** Strips identity: age, sex, dates relative to today, dx titles, item generic names, vitals — never name/phone/id. */
final class DeidentifiedContextBuilder
{
    public function summary(Visit $visit, int $limit = 5): VisitSummaryRequest
    {
        $patient = $visit->patient;
        $visits = Visit::query()->where('patient_id', $visit->patient_id)->whereKeyNot($visit->id)
            ->with(['currentPrescription.items', 'latestVitals'])->orderByDesc('started_at')->limit($limit)->get()
            ->map(function (Visit $v): array {
                $rx = $v->currentPrescription;

                return [
                    'days_ago' => (int) $v->started_at->diffInDays(Clock::now()),
                    'type' => $v->type->value,
                    'complaints' => array_values(array_map(fn ($c) => trim(($c['text'] ?? '').' '.($c['duration'] ?? '')), $v->chief_complaints)),
                    'findings' => $v->examination_findings,
                    'diagnoses' => array_values(array_map(fn ($d) => trim(($d['title'] ?? '').' '.($d['icd10_code'] ?? '')), $v->diagnoses)),
                    'rx' => $rx !== null && ! $rx->isDraft() ? $rx->items->map(fn ($i) => trim($i->generic_name.' '.($i->strength ?? '').' '.($i->dose_schedule ?? '').' '.($i->duration_text ?? '')))->values()->all() : [],
                    'vitals' => $v->latestVitals === null ? null : array_filter(['bp' => $v->latestVitals->bp_systolic !== null ? $v->latestVitals->bp_systolic.'/'.$v->latestVitals->bp_diastolic : null, 'weight_kg' => $v->latestVitals->weight_kg, 'temp_c' => $v->latestVitals->temperature_c]),
                ];
            })->values()->all();

        return new VisitSummaryRequest($patient->age_years, $patient->gender?->value, $visits, (string) app()->getLocale());
    }

    /** @param  array<string, mixed>  $input  the writer's current values (chief_complaints, examination_findings, vitals) */
    public function differentials(Visit $visit, array $input): DifferentialRequest
    {
        $patient = $visit->patient;
        $complaints = array_values(array_map(fn ($c) => is_array($c) ? array_intersect_key($c, array_flip(['text', 'duration'])) : ['text' => (string) $c], (array) ($input['chief_complaints'] ?? $visit->chief_complaints)));
        $vitals = isset($input['vitals']) && is_array($input['vitals']) ? array_intersect_key($input['vitals'], array_flip(['bp_systolic', 'bp_diastolic', 'pulse_bpm', 'temperature_c', 'spo2_percent', 'respiratory_rate', 'weight_kg', 'height_cm', 'bmi', 'blood_glucose_mgdl'])) : null;

        return new DifferentialRequest($patient->age_years, $patient->gender?->value, $complaints, isset($input['examination_findings']) ? (string) $input['examination_findings'] : $visit->examination_findings, $vitals, (string) app()->getLocale());
    }
}
