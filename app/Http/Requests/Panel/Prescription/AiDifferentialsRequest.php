<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\Visit;
use Illuminate\Foundation\Http\FormRequest;

/** POST /panel/visits/{visit}/ai/differentials {chief_complaints, examination_findings, vitals} (PRESCRIPTION.md §5.7). */
final class AiDifferentialsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $visit = $this->route('visit');

        return $visit instanceof Visit && ($this->user('web')?->can('write', $visit) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['chief_complaints' => ['sometimes', 'array', 'max:30'], 'examination_findings' => ['nullable', 'string', 'max:4000'], 'vitals' => ['nullable', 'array']];
    }
}
