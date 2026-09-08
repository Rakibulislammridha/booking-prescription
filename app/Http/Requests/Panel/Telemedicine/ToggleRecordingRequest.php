<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Telemedicine;

use Illuminate\Foundation\Http\FormRequest;

/** POST /panel/telemedicine/{room}/recording */
final class ToggleRecordingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['on' => ['required', 'boolean']];
    }
}
