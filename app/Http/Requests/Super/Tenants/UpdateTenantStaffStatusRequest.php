<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Tenants;

use Illuminate\Foundation\Http\FormRequest;

/** Activate / deactivate a clinic user from the console. */
final class UpdateTenantStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function isActive(): bool
    {
        return (bool) $this->validated('is_active');
    }
}
