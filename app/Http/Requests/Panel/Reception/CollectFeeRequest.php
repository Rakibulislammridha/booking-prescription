<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Reception;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class CollectFeeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $appointment = $this->route('appointment');

        return $user instanceof User && $appointment instanceof Appointment && $user->can('collect', $appointment);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount_paisa' => ['nullable', 'integer', 'min:0'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
