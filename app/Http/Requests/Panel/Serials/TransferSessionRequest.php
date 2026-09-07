<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class TransferSessionRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('session');

        return $user instanceof User && $subject !== null && $user->can('transferSession', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'target_session' => ['required', 'string', 'size:26', Rule::exists('session_instances', 'public_id')],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
