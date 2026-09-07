<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Data\VitalsData;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Foundation\Http\FormRequest;

/** POST /panel/visits/{visit}/vitals and PATCH /panel/vitals/{vital} (PRESCRIPTION.md §4.2). */
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
            'temperature_c' => ['nullable', 'numeric', 'between:30,45'],
            'spo2_percent' => ['nullable', 'integer', 'between:30,100'],
            'respiratory_rate' => ['nullable', 'integer', 'between:4,90'],
            'weight_kg' => ['nullable', 'numeric', 'gt:0', 'lt:500'],
            'height_cm' => ['nullable', 'numeric', 'gt:0', 'lt:300'],
            'blood_glucose_mgdl' => ['nullable', 'integer', 'between:10,1500'],
            'notes' => ['nullable', 'string', 'max:255'],
            'reviewed' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): VitalsData
    {
        return VitalsData::fromRequest($this);
    }
}
