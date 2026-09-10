<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Tenants;

use App\Domain\Tenancy\Rules\NotReservedSlug;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Changing a clinic's subdomain (`RenameTenantSlug`). The operator must type the CURRENT slug back — this is the
 * address every printed slip and SMS link of the clinic carries, and a mistyped rename takes a hospital off the
 * internet — and the new one obeys exactly the rules of creation.
 */
final class RenameTenantSlugRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $tenant = $this->route('tenant');
        $current = $tenant instanceof Tenant ? $tenant->slug : '';

        return [
            'slug' => ['required', 'string', 'min:2', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/', new NotReservedSlug, Rule::notIn([$current]), Rule::unique(Tenant::class, 'slug')],
            'confirm_slug' => ['required', 'string', Rule::in([$current])],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => __('tenancy.slug_invalid'),
            'slug.unique' => __('saas.onboarding.slug_taken'),
            'slug.not_in' => __('super.tenants.rename.same_slug'),
            'confirm_slug.in' => __('super.tenants.rename.confirm_mismatch'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'confirm_slug' => mb_strtolower(trim((string) $this->input('confirm_slug'))),
        ]);
    }

    public function slug(): string
    {
        return (string) $this->validated('slug');
    }
}
