<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Domain\Serials\Enums\CancelReason;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CancelSerialRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $subject = $this->route('serial');

        return $user instanceof User && $subject !== null && $user->can('cancel', $subject);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::in(array_diff(CancelReason::values(), ['transferred', 'session_cancelled']))],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
