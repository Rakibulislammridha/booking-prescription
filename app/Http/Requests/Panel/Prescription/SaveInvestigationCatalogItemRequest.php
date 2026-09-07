<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Prescription\Enums\InvestigationCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST / PUT /panel/investigation-catalog (PRESCRIPTION.md §4.5). */
final class SaveInvestigationCatalogItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can(Permission::PrescriptionsWrite->value) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'code' => ['nullable', 'string', 'max:24'],
            'name' => ['required', 'string', 'max:200'],
            'name_bn' => ['nullable', 'string', 'max:200'],
            'category' => ['sometimes', Rule::enum(InvestigationCategory::class)],
            'price_paisa' => ['sometimes', 'integer', 'min:0'],
            'prep_instructions' => ['nullable', 'string', 'max:500'],
            'prep_instructions_bn' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:0,32000'],
        ];
    }
}
