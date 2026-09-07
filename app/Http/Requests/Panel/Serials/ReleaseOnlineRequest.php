<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class ReleaseOnlineRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('session');

        return $user instanceof User && $subject !== null && $user->can('adjustSplit', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'count' => ['nullable', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
