<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST …/drawing multipart {json, png?} (PRESCRIPTION.md §4.11). `json` is the DrawingJson document (string or array). */
final class DrawingRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');

        return $rx instanceof Prescription && ($this->user('web')?->can('write', $rx) ?? false);
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('json'))) {
            $decoded = json_decode((string) $this->input('json'), true);
            $this->merge(['json' => is_array($decoded) ? $decoded : null]);
        }
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'json' => ['required', 'array'],
            'json.canvas' => ['required', 'array'],
            'json.canvas.w' => ['required', 'numeric', 'min:1'],
            'json.canvas.h' => ['required', 'numeric', 'min:1'],
            'json.canvas.template' => ['required', Rule::in((array) config('prescription.drawing_backgrounds'))],
            'json.strokes' => ['present', 'array'],
            'json.texts' => ['sometimes', 'array'],
            'png' => ['nullable', 'file', 'mimes:png', 'max:8192'],
        ];
    }
}
