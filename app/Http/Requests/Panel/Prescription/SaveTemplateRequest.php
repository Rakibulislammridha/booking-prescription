<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Data\TemplateData;
use App\Models\Tenant\PrescriptionTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST / PUT /panel/prescription-templates (PRESCRIPTION.md §3.6). */
final class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $template = $this->route('template');
        $user = $this->user('web');

        return $template instanceof PrescriptionTemplate ? ($user?->can('update', $template) ?? false) : ($user?->can('create', PrescriptionTemplate::class) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'shorthand' => ['nullable', 'string', 'max:24', 'regex:/^\/?[a-z0-9_-]+$/i'],
            'icd10_code' => ['nullable', 'string', 'max:8', Rule::catalog('icd10_codes')],
            'diagnosis_title' => ['nullable', 'string', 'max:200'],
            'is_shared' => ['sometimes', 'boolean'],
            'from_prescription_id' => ['nullable', 'string', 'size:26', Rule::exists('prescriptions', 'public_id')],
            'include_clinical' => ['sometimes', 'boolean'],
            'body' => ['nullable', 'array'],
            'body.chief_complaints' => ['nullable', 'array'],
            'body.examination_findings' => ['nullable', 'string', 'max:4000'],
            'body.advice' => ['nullable', 'array'],
            'body.investigations' => ['nullable', 'array'],
            'body.follow_up_days' => ['nullable', 'integer', 'between:0,365'],
            'items' => ['nullable', 'array', 'max:40'],
            'items.*.key' => ['nullable', 'string', 'max:40'],
            'items.*.drug' => ['required', 'array'],
            'items.*.drug.generic_id' => ['nullable', 'integer', Rule::catalog('generics', false)],
            'items.*.drug.brand_id' => ['nullable', 'integer', Rule::catalog('brands', false)],
            'items.*.drug.strength_id' => ['nullable', 'integer', Rule::catalog('strengths', false)],
            'items.*.drug.custom_brand_id' => ['nullable', 'integer', Rule::exists('custom_brands', 'id')],
            'items.*.shorthand' => ['present', 'nullable', 'string', 'max:300'],
        ];
    }

    public function toData(): TemplateData
    {
        return TemplateData::fromArray($this->validated());
    }
}
