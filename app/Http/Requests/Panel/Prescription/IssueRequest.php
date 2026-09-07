<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Data\IssueRequest as IssueData;
use App\Domain\Prescription\Enums\PrescriptionLanguage;
use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/prescriptions/{prescription}/issue {language?, print, acknowledged_warnings[], expected_updated_at?, add_to_medication_list?}. */
final class IssueRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');

        return $rx instanceof Prescription && ($this->user('web')?->can('issue', $rx) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'language' => ['nullable', Rule::enum(PrescriptionLanguage::class)],
            'print' => ['sometimes', 'boolean'],
            'acknowledged_warnings' => ['sometimes', 'array'],
            'acknowledged_warnings.*' => ['string', 'max:200'],
            'expected_updated_at' => ['nullable', 'date'],
            'add_to_medication_list' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): IssueData
    {
        $v = $this->validated();

        return new IssueData(
            language: isset($v['language']) ? (string) $v['language'] : null,
            print: (bool) ($v['print'] ?? false),
            acknowledgedWarnings: array_values((array) ($v['acknowledged_warnings'] ?? [])),
            expectedUpdatedAt: isset($v['expected_updated_at']) ? (string) $v['expected_updated_at'] : null,
            addToMedicationList: (bool) ($v['add_to_medication_list'] ?? false),
        );
    }
}
