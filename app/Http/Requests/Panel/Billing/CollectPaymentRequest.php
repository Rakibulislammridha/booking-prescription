<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Billing;

use App\Domain\Billing\Enums\PaymentMethod;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Counter collection. The amount is validated against the invoice again in the Action, under the row lock. */
final class CollectPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $invoice = $this->route('invoice');

        return $user instanceof User && $invoice instanceof Invoice && $user->can('collect', $invoice);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'amount_paisa' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in([PaymentMethod::Cash->value, PaymentMethod::Card->value, PaymentMethod::Other->value])],
            'receipt_number' => ['nullable', 'string', 'max:24'],
            'note' => ['nullable', 'string', 'max:255'],
            'client_event_id' => ['nullable', 'string', 'size:26'],
        ];
    }
}
