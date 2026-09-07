<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST …/send {channel, to?} (PRESCRIPTION.md §7.7). */
final class SendRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');

        return $rx instanceof Prescription && ($this->user('web')?->can('send', $rx) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['channel' => ['required', Rule::in(['sms', 'whatsapp', 'email'])], 'to' => ['nullable', 'string', 'max:255']];
    }
}
