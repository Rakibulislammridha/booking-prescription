<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Billing;

use App\Domain\SaaS\Data\RecordPaymentData;
use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Models\Central\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A manual payment against a platform invoice — the bank transfer, bKash merchant payment or cash that actually
 * arrived. Integer paisa, and a REFERENCE is required: it becomes the idempotency key
 * (`manual:{invoice}:{reference}`), so the same transfer pasted twice, or a double-clicked button, settles the
 * invoice exactly once. A cash payment has a receipt number; that is its reference.
 */
final class RecordPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'amount_paisa' => ['required', 'integer', 'min:1', 'max:100000000000'],
            'method' => ['required', Rule::in(SubscriptionPaymentMethod::values())],
            'reference' => ['required', 'string', 'max:64', 'regex:/^[\pL\pN][\pL\pN \-_\/.#]{0,63}$/u'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toData(SubscriptionInvoice $invoice): RecordPaymentData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $reference = trim((string) $v['reference']);

        return new RecordPaymentData(
            amountPaisa: (int) $v['amount_paisa'],
            method: SubscriptionPaymentMethod::from((string) $v['method']),
            gatewayTxnId: $reference,
            idempotencyKey: 'manual:'.$invoice->id.':'.$reference,
            gatewayPayload: [
                'recorded_at' => CarbonImmutable::now()->toIso8601String(),
                'note' => isset($v['note']) ? (string) $v['note'] : null,
                'source' => 'console',
            ],
            recordedBySuperAdminId: (int) $this->user('super')?->getAuthIdentifier(),
            reference: $reference,
        );
    }
}
