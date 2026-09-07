<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Data\DraftPayload;
use App\Domain\Prescription\Enums\PrescriptionLanguage;
use App\Domain\Prescription\Enums\ReferralType;
use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * PATCH /panel/prescriptions/{prescription}/draft (PRESCRIPTION.md §4.13). Catalog ids through CatalogIdExists
 * (Rule::catalog), tenant rows through exists; the shorthand itself is validated by the server parse (422 with the
 * item key + issues), not here. Every section is optional except `items` when present.
 */
final class SaveDraftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');

        return $rx instanceof Prescription && ($this->user('web')?->can('write', $rx) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'language' => ['sometimes', Rule::enum(PrescriptionLanguage::class)],
            'visit' => ['sometimes', 'array'],
            'visit.chief_complaints' => ['sometimes', 'array', 'max:30'],
            'visit.chief_complaints.*.text' => ['required', 'string', 'max:200'],
            'visit.chief_complaints.*.text_bn' => ['nullable', 'string', 'max:200'],
            'visit.chief_complaints.*.duration' => ['nullable', 'string', 'max:24'],
            'visit.examination_findings' => ['nullable', 'string', 'max:4000'],
            'visit.diagnoses' => ['sometimes', 'array', 'max:20'],
            'visit.diagnoses.*.icd10_code' => ['nullable', 'string', 'max:8', Rule::catalog('icd10_codes')],
            'visit.diagnoses.*.title' => ['required', 'string', 'max:200'],
            'visit.diagnoses.*.kind' => ['required', Rule::in(['provisional', 'final'])],
            'visit.follow_up_on' => ['nullable', 'date_format:Y-m-d'],
            'visit.follow_up_note' => ['nullable', 'string', 'max:255'],
            'visit.private_notes' => ['nullable', 'string', 'max:4000'],
            'follow_up_days' => ['nullable', 'integer', 'between:0,365'],
            'create_booking' => ['sometimes', 'boolean'],
            'vitals_reviewed' => ['sometimes', 'boolean'],
            'items' => ['sometimes', 'array', 'max:40'],
            'items.*.key' => ['required', 'string', 'max:40'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.sort_order' => ['nullable', 'integer', 'min:0'],
            'items.*.drug' => ['nullable', 'array'],
            'items.*.drug.generic_id' => ['nullable', 'integer', Rule::catalog('generics', false)],
            'items.*.drug.brand_id' => ['nullable', 'integer', Rule::catalog('brands', false)],
            'items.*.drug.strength_id' => ['nullable', 'integer', Rule::catalog('strengths', false)],
            'items.*.drug.custom_brand_id' => ['nullable', 'integer', Rule::exists('custom_brands', 'id')],
            'items.*.shorthand' => ['present', 'nullable', 'string', 'max:300'],
            'items.*.instruction_bn' => ['nullable', 'string', 'max:255'],
            'items.*.safety_overrides' => ['sometimes', 'array', 'max:20'],
            'items.*.safety_overrides.*.fingerprint' => ['required', 'string', 'max:200'],
            'items.*.safety_overrides.*.reason' => ['required', 'string', 'min:10', 'max:500'],
            'investigations' => ['sometimes', 'array', 'max:40'],
            'investigations.*.key' => ['required', 'string', 'max:40'],
            'investigations.*.id' => ['nullable', 'integer'],
            'investigations.*.investigation_catalog_id' => ['nullable', 'integer', Rule::exists('investigation_catalog', 'id')],
            'investigations.*.name' => ['nullable', 'string', 'max:200', 'required_without:investigations.*.investigation_catalog_id'],
            'investigations.*.name_bn' => ['nullable', 'string', 'max:200'],
            'investigations.*.external_diagnostic_centre_id' => ['nullable', 'integer', Rule::exists('external_diagnostic_centres', 'id')],
            'investigations.*.referral_note' => ['nullable', 'string', 'max:500'],
            'investigations.*.is_urgent' => ['sometimes', 'boolean'],
            'advice' => ['sometimes', 'array', 'max:40'],
            'advice.*.key' => ['required', 'string', 'max:40'],
            'advice.*.id' => ['nullable', 'integer'],
            'advice.*.advice_snippet_id' => ['nullable', 'integer', Rule::exists('advice_snippets', 'id')],
            'advice.*.text' => ['nullable', 'string', 'max:1000', 'required_without:advice.*.advice_snippet_id'],
            'advice.*.text_bn' => ['nullable', 'string', 'max:1000'],
            'referrals' => ['sometimes', 'array', 'max:10'],
            'referrals.*.key' => ['required', 'string', 'max:40'],
            'referrals.*.id' => ['nullable', 'integer'],
            'referrals.*.type' => ['required', Rule::enum(ReferralType::class)],
            'referrals.*.referred_to_doctor_id' => ['nullable', 'integer', Rule::exists('doctors', 'id')->whereNull('deleted_at')],
            'referrals.*.external_diagnostic_centre_id' => ['nullable', 'integer', Rule::exists('external_diagnostic_centres', 'id')],
            'referrals.*.referred_to_name' => ['nullable', 'string', 'max:200', 'required_without_all:referrals.*.referred_to_doctor_id,referrals.*.external_diagnostic_centre_id'],
            'referrals.*.referred_to_specialty' => ['nullable', 'string', 'max:120'],
            'referrals.*.note' => ['nullable', 'string', 'max:2000'],
            'referrals.*.is_urgent' => ['sometimes', 'boolean'],
            'expected_updated_at' => ['nullable', 'date'],
        ];
    }

    public function toData(): DraftPayload
    {
        return DraftPayload::fromRequest($this);
    }
}
