<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;

/** POST …/handwriting multipart {page, png} (PRESCRIPTION.md §4.12). */
final class HandwritingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');

        return $rx instanceof Prescription && ($this->user('web')?->can('write', $rx) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['page' => ['required', 'integer', 'between:1,3'], 'png' => ['required', 'file', 'mimes:png', 'max:8192']];
    }
}
