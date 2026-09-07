<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Billing;

use App\Domain\Billing\Data\RefundRequest;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\RefundReason;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A refund always needs a reason code (BRIEF §5.F); `amount_paisa` omitted means "everything refundable". */
final class IssueRefundRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $invoice = $this->route('invoice');

        return $user instanceof User && $invoice instanceof Invoice && $user->can('refund', $invoice);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'payment' => ['required', 'string', 'size:26'],
            'amount_paisa' => ['nullable', 'integer', 'min:1'],
            'reason_code' => ['required', Rule::in(RefundReason::values())],
            'note' => ['nullable', 'string', 'max:255'],
            'method' => ['nullable', Rule::in(PaymentMethod::values())],
            'auto_process' => ['nullable', 'boolean'],
        ];
    }

    public function toData(): RefundRequest
    {
        $amount = $this->validated('amount_paisa');
        $method = $this->validated('method');

        return new RefundRequest(
            reasonCode: RefundReason::from((string) $this->validated('reason_code')),
            amountPaisa: $amount === null ? null : (int) $amount,
            note: $this->validated('note'),
            method: $method === null ? null : PaymentMethod::from((string) $method),
            autoProcess: (bool) ($this->validated('auto_process') ?? true),
        );
    }
}
