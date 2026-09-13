<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Http\Requests\Panel\Serials\Concerns\AuthorisesTransferTarget;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `transfer` on the serial being moved AND `view` on the chamber it is moved into — see AuthorisesTransferTarget. */
final class TransferSerialRequest extends FormRequest
{
    use AuthorisesTransferTarget;

    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('serial');

        return $user instanceof User
            && $subject !== null
            && $user->can('transfer', $subject)
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
