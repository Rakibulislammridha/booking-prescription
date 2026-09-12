<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;

/**
 * The doctor's session roster (`panel.queue.doctor` / `panel.queue.doctor.roster`): every serial of the session in
 * serial-number order — the same order the desk board lists them — with the patient (name, sex, age, code), the
 * vitals the compounder recorded, the visit and the current prescription, so the page can offer the right action
 * per row (call / start / prescribe / view / print). Staff-only: the public QueueState never carries any of this.
 *
 * Five queries whatever the session size: serials, patients, visits (+ latest vitals, + current prescription).
 */
final class SessionRosterBuilder
{
    /** @return array<string, array<string, mixed>> keyed by serial public id */
    public function build(SessionInstance $session): array
    {
        $serials = Serial::query()
            ->where('session_instance_id', $session->id)
            ->whereNotIn('status', [SerialStatus::Cancelled->value, SerialStatus::Postponed->value])
            ->orderBy('number')
            ->get(['id', 'public_id', 'patient_id', 'display_code', 'number', 'position', 'status', 'priority', 'called_at', 'consultation_started_at', 'completed_at']);

        if ($serials->isEmpty()) {
            return [];
        }

        $patients = Patient::query()->whereIn('id', $serials->pluck('patient_id')->filter()->unique()->all())->get()->keyBy('id');
        $visits = Visit::query()->whereIn('serial_id', $serials->pluck('id')->all())
            ->with(['latestVitals', 'currentPrescription:id,public_id,status'])
            ->get()->keyBy('serial_id');

        $out = [];

        foreach ($serials as $serial) {
            $patient = $patients->get($serial->patient_id);
            $visit = $visits->get($serial->id);
            $rx = $visit?->currentPrescription;

            $out[$serial->public_id] = [
                'serial_id' => $serial->public_id,
                'code' => $serial->display_code,
                'number' => $serial->number,
                'status' => $serial->status->value,
                'priority' => $serial->priority->value,
                'called_at' => $serial->called_at?->toIso8601ZuluString(),
                'consultation_started_at' => $serial->consultation_started_at?->toIso8601ZuluString(),
                'patient' => $patient instanceof Patient ? [
                    'name' => $patient->name,
                    'age_text' => $patient->age_text,
                    'sex' => $patient->gender?->value,
                    'patient_code' => $patient->patient_code,
                ] : null,
                'vitals' => $visit?->latestVitals instanceof Vital ? self::vitals($visit->latestVitals) : null,
                'visit_id' => $visit?->public_id,
                'prescription' => $rx === null ? null : ['id' => $rx->public_id, 'status' => $rx->status->value],
            ];
        }

        return $out;
    }

    /**
     * The summary the row shows (BP · pulse · temperature · SpO2 · weight). Temperature travels in °C, the
     * clinical canonical unit; the client formats °F, exactly as the writer's vitals card does.
     *
     * @return array<string, mixed>
     */
    private static function vitals(Vital $v): array
    {
        return [
            'bp_systolic' => $v->bp_systolic,
            'bp_diastolic' => $v->bp_diastolic,
            'pulse_bpm' => $v->pulse_bpm,
            'temperature_c' => $v->temperature_c,
            'spo2_percent' => $v->spo2_percent,
            'weight_kg' => $v->weight_kg,
            'recorded_at' => $v->recorded_at->toIso8601String(),
        ];
    }
}
