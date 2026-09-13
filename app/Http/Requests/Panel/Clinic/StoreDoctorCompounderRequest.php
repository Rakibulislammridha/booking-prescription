<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The staff account is named by its public ULID, the way every other user reference in the panel is (CONVENTIONS §5)
 * — this used to take a raw bigint `user_id`, which was the one place in the feature that leaked the internal key
 * into a request body. Nothing escalated either way: `users` is a tenant-schema table, so the exists rule never sees
 * another clinic's row, and AssignCompounder asserts the tenant and the `compounder` role again regardless.
 */
final class StoreDoctorCompounderRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user('web')?->can('manageCompounders', $doctor) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'user_public_id' => ['required', 'string', 'size:26', Rule::exists('users', 'public_id')->whereNull('deleted_at')],
        ];
    }
}
