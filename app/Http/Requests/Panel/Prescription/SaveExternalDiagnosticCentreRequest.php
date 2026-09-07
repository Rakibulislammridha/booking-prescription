<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Clinic\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;

/** POST / PUT /panel/external-diagnostic-centres. */
final class SaveExternalDiagnosticCentreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can(Permission::PrescriptionsWrite->value) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:200'],
            'address' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:20'],
            'contact_person' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
