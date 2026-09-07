<?php

declare(strict_types=1);

namespace App\Http\Requests\Site\Portal;

use Illuminate\Foundation\Http\FormRequest;

/** PATCH /portal/acting-for — the family member the logged-in owner acts for (ARCHITECTURE §6.3). */
final class ActingForRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('patient') !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['patient' => ['required', 'string', 'size:26']];
    }
}
