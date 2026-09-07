<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials;

use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class SerialReasonRequest extends FormRequest
{
    /** Shared by several actions; the controller authorises the specific ability against the policy. */
    public function authorize(): bool
    {
        return $this->user() instanceof User;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:255'],
            'client_event_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
