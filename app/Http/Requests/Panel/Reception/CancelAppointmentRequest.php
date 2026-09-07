<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Reception;

use App\Domain\Serials\Enums\CancelReason;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CancelAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $appointment = $this->route('appointment');

        return $user instanceof User && $appointment instanceof Appointment && $user->can('cancel', $appointment);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'reason_code' => ['required', Rule::in(array_diff(CancelReason::values(), [CancelReason::Transferred->value, CancelReason::SessionCancelled->value]))],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
