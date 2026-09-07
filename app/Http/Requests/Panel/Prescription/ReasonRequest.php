<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;

/** POST …/amend and …/void {reason} (≥ 5 chars). The ability is the route's `defaults('ability')`. */
final class ReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');
        $ability = (string) ($this->route()?->defaults['ability'] ?? 'amend');

        return $rx instanceof Prescription && ($this->user('web')?->can($ability, $rx) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:255']];
    }

    public function reason(): string
    {
        return trim((string) $this->validated()['reason']);
    }
}
