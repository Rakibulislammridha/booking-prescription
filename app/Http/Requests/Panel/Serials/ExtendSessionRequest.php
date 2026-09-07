<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class ExtendSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('session');

        return $user instanceof User && $subject !== null && $user->can('extend', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'extra' => ['required', 'integer', 'between:1,200'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
