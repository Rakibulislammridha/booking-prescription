<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/** `PUT catalog/icd10/{id}/aliases` — the plain-language aliases (one per line or comma-separated) and the Bangla title. */
final class UpdateIcd10AliasesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'aliases' => ['present', 'array', 'max:40'],
            'aliases.*' => ['string', 'max:80'],
            'title_bn' => ['nullable', 'string', 'max:255'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $raw = $this->input('aliases');

        if (is_string($raw)) {
            $raw = preg_split('/[\n,|]+/', $raw) ?: [];
        }

        $this->merge(['aliases' => array_values(array_filter(array_map(fn ($a) => is_string($a) ? trim($a) : '', (array) $raw), fn (string $a) => $a !== ''))]);
    }

    /** @return list<string> */
    public function aliases(): array
    {
        /** @var list<string> $aliases */
        $aliases = array_values((array) $this->validated('aliases'));

        return $aliases;
    }
}
