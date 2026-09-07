<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Prescription\Enums\AdviceCategory;
use App\Models\Tenant\AdviceSnippet;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST / PUT /panel/advice-snippets (PRESCRIPTION.md §4.7). */
final class SaveAdviceSnippetRequest extends FormRequest
{
    public function authorize(): bool
    {
        $snippet = $this->route('snippet');
        $user = $this->user('web');

        return $snippet instanceof AdviceSnippet ? ($user?->can('update', $snippet) ?? false) : ($user?->can('create', AdviceSnippet::class) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'shorthand' => ['nullable', 'string', 'max:24', 'regex:/^\/?[a-z0-9_-]+$/i'],
            'category' => ['nullable', Rule::enum(AdviceCategory::class)],
            'text' => ['required', 'string', 'max:1000'],
            'text_bn' => ['nullable', 'string', 'max:1000'],
            'is_shared' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'clinic' => ['sometimes', 'boolean'],
        ];
    }
}
