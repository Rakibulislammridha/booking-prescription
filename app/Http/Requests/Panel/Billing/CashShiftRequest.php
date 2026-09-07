<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Billing;

use App\Models\Tenant\CashShift;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

/** Open (`opening_float_paisa`) and close (`counted_cash_paisa`) share one request; the route decides which. */
final class CashShiftRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User) {
            return false;
        }

        $shift = $this->route('shift');

        return $shift instanceof CashShift ? $user->can('close', $shift) : $user->can('open', CashShift::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'opening_float_paisa' => ['nullable', 'integer', 'min:0'],
            'counted_cash_paisa' => ['nullable', 'integer', 'min:0'],
            'branch' => ['nullable', 'string', 'size:26'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
