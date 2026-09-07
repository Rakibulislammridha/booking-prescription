<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Clears `logo_path` / `signature_path` on the pad row. */
final class RemovePadAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user('web')?->can('designPad', $doctor) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['kind' => ['required', Rule::in(['logo', 'signature'])]];
    }

    public function kind(): string
    {
        return (string) $this->validated('kind');
    }
}
