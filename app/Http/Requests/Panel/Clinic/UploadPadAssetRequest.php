<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Pad logo / scanned signature upload. Stored on the `uploads` disk under TenantPath (ARCHITECTURE §8.7). */
final class UploadPadAssetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user('web')?->can('designPad', $doctor) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::in(['logo', 'signature'])],
            'file' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
        ];
    }

    public function kind(): string
    {
        return (string) $this->validated('kind');
    }
}
