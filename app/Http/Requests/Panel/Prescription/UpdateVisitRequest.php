<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Data\VisitData;
use App\Models\Tenant\Visit;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /panel/visits/{visit} — the visit keys without going through the draft (SCHEMA §3.4). */
final class UpdateVisitRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visit = $this->route('visit');

        return $visit instanceof Visit && ($this->user('web')?->can('write', $visit) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'chief_complaints' => ['sometimes', 'array', 'max:30'],
            'chief_complaints.*.text' => ['required', 'string', 'max:200'],
            'chief_complaints.*.text_bn' => ['nullable', 'string', 'max:200'],
            'chief_complaints.*.duration' => ['nullable', 'string', 'max:24'],
            'examination_findings' => ['nullable', 'string', 'max:4000'],
            'diagnoses' => ['sometimes', 'array', 'max:20'],
            'diagnoses.*.icd10_code' => ['nullable', 'string', 'max:8', Rule::catalog('icd10_codes')],
            'diagnoses.*.title' => ['required', 'string', 'max:200'],
            'diagnoses.*.kind' => ['required', Rule::in(['provisional', 'final'])],
            'follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'follow_up_note' => ['nullable', 'string', 'max:255'],
            'private_notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function toData(): VisitData
    {
        return VisitData::fromArray($this->validated());
    }
}
