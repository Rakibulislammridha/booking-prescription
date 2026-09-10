<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/** `PUT catalog/{kind}/{id}/active {active}` — the browser's inline switch. */
final class ToggleActiveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['active' => ['required', 'boolean']];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['active' => $this->boolean('active')]);
    }
}
