<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/prescriptions/{prescription}/apply-template/{template} {mode: append|replace}. */
final class ApplyTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');
        $template = $this->route('template');
        $user = $this->user('web');

        return $rx instanceof Prescription && $template instanceof PrescriptionTemplate && $user !== null && $user->can('write', $rx) && $user->can('view', $template);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['mode' => ['sometimes', Rule::in(['append', 'replace'])]];
    }
}
