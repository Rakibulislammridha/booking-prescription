<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Http\Requests\Panel\Serials\Concerns\AuthorisesTransferTarget;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `transferSession` on the session being emptied AND `view` on the one it empties into — see
 * AuthorisesTransferTarget. The bulk path carries the whole queue across, so the one-sided gap was the same gap
 * multiplied by however many patients were waiting.
 */
final class TransferSessionRequest extends FormRequest
{
    use AuthorisesTransferTarget;

    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('session');

        return $user instanceof User
            && $subject !== null
            && $user->can('transferSession', $subject)
            && $this->targetIsVisibleTo($user, $this->input('target_session'));
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
