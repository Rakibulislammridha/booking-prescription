<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class DelaySessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('session');

        return $user instanceof User && $subject !== null && $user->can('delay', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'delay_minutes' => ['required', 'integer', 'between:0,600'],
            'message' => ['nullable', 'string', 'max:255'],
        ];
    }
}
