<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Data\VitalsData;
use App\Domain\Prescription\Support\Temperature;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /panel/visits/{visit}/vitals and PATCH /panel/vitals/{vital} (PRESCRIPTION.md §4.2).
 *
 * Temperature arrives as `temperature_f` — the unit a compounder reads off a thermometer — and is validated in
 * °F (86–113, i.e. the column's 30–45 °C); `VitalsData::fromRequest()` converts it to the stored °C once. A
 * `temperature_c` in the body is refused rather than ignored, so a stale client cannot lose a reading silently.
 */
final class RecordVitalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visit = $this->route('visit');
        $vital = $this->route('vital');

        if ($vital instanceof Vital) {
            $visit = $vital->visit;
        }

        return $visit instanceof Visit && ($this->user('web')?->can('recordVitals', $visit) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'bp_systolic' => ['nullable', 'integer', 'between:40,300'],
            'bp_diastolic' => ['nullable', 'integer', 'between:20,200'],
            'pulse_bpm' => ['nullable', 'integer', 'between:20,300'],
            'temperature_f' => ['nullable', 'numeric', 'between:'.Temperature::MIN_F.','.Temperature::MAX_F],
            'temperature_c' => ['prohibited'],
            'spo2_percent' => ['nullable', 'integer', 'between:30,100'],
            'respiratory_rate' => ['nullable', 'integer', 'between:4,90'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0', 'lt:500'],
            'height_cm' => ['nullable', 'numeric', 'gt:0', 'lt:300'],
            'blood_glucose_mgdl' => ['nullable', 'integer', 'between:10,1500'],
            'notes' => ['nullable', 'string', 'max:255'],
            'reviewed' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        $range = __('prescriptions.vitals.errors.temperature_range', ['min_f' => Temperature::MIN_F, 'max_f' => Temperature::MAX_F, 'min_c' => Temperature::MIN_C, 'max_c' => Temperature::MAX_C]);

        return [
            'temperature_f.numeric' => $range,
            'temperature_f.between' => $range,
            'temperature_c.prohibited' => __('prescriptions.vitals.errors.temperature_celsius'),
        ];
    }

    public function toData(): VitalsData
    {
        return VitalsData::fromRequest($this);
    }
}
