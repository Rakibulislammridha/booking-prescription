<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Telemedicine;

use App\Domain\Telemedicine\Enums\SessionEndReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/telemedicine/{room}/end. `completed` is the one reason that completes the serial. */
final class EndCallRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;                 // the controller's policy check is the authorisation
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['sometimes', Rule::in(SessionEndReason::values())],
        ];
    }

    public function reason(): SessionEndReason
    {
        return SessionEndReason::tryFrom((string) $this->validated('reason', 'completed')) ?? SessionEndReason::Completed;
    }
}
