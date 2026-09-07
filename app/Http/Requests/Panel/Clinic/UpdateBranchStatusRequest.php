<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Models\Tenant\Branch;
use Illuminate\Foundation\Http\FormRequest;

/** Activate / deactivate from the branch list without re-posting the whole branch form. */
final class UpdateBranchStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch && ($this->user('web')?->can('update', $branch) ?? false);
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
