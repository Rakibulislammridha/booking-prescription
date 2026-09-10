<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Tenants;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Step two of deleting a clinic: the slug typed back, letter for letter, and the consequence acknowledged. The
 * business guards (unsettled invoices, a fresh export) are `DeleteTenant`'s and are not repeated here.
 */
final class DeleteTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');

        return [
            'slug' => ['required', 'string', Rule::in([$tenant instanceof Tenant ? $tenant->slug : ''])],
            'acknowledge' => ['accepted'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.in' => __('super.tenants.delete.error.slug_mismatch'),
            'acknowledge.accepted' => __('super.tenants.delete.error.acknowledge'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['slug' => mb_strtolower(trim((string) $this->input('slug')))]);
    }
}
