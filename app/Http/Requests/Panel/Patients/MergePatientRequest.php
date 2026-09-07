<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/patients/{patient}/merge — `{patient}` wins, `loser_public_id` is folded into it. */
final class MergePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user('web')?->can('merge', $patient) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'loser_public_id' => ['required', 'string', 'size:26', Rule::exists('patients', 'public_id')->whereNull('deleted_at')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
