<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

/** Activate / deactivate a staff account from the list. UpdateStaffUser still refuses self-deactivation. */
final class UpdateStaffStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user('web')?->can('update', $target) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']];
    }

    public function isActive(): bool
    {
        return (bool) $this->validated('is_active');
    }
}
