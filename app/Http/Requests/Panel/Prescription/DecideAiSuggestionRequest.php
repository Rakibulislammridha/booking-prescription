<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\AiSuggestion;
use Illuminate\Foundation\Http\FormRequest;

/** PATCH /panel/ai-suggestions/{suggestion} {accepted, accepted_fragment?}. */
final class DecideAiSuggestionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $s = $this->route('suggestion');
        $doctorId = $this->user('web')?->doctor()->value('id');

        return $s instanceof AiSuggestion && $doctorId !== null && (int) $doctorId === $s->doctor_id;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['accepted' => ['required', 'boolean'], 'accepted_fragment' => ['nullable', 'string', 'max:2000']];
    }
}
