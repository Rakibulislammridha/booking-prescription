<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Billing;

use App\Domain\Billing\Data\DiscountRequest;
use App\Domain\Billing\Enums\DiscountReason;
use App\Domain\Billing\Enums\DiscountType;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ApplyDiscountRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $invoice = $this->route('invoice');

        return $user instanceof User && $invoice instanceof Invoice && $user->can('discount', $invoice);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(DiscountType::values())],
            'value' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'reason_code' => ['required', Rule::in(DiscountReason::values())],
            'note' => ['nullable', 'string', 'max:255'],
            'approved_by' => ['nullable', 'string', 'size:26'],
        ];
    }

    public function toData(): DiscountRequest
    {
        $approver = $this->validated('approved_by');
        $approverId = $approver === null ? null : User::query()->where('public_id', (string) $approver)->value('id');

        return new DiscountRequest(
            type: DiscountType::from((string) $this->validated('type')),
            value: (string) $this->validated('value'),
            reasonCode: DiscountReason::from((string) $this->validated('reason_code')),
            note: $this->validated('note'),
            approvedByUserId: $approverId === null ? null : (int) $approverId,
        );
    }
}
