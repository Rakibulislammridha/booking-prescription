<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Billing;

use App\Models\Central\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Raise an invoice for a clinic's CURRENT period by hand (an out-of-cycle charge, a corrected amount). The
 * amount, if given, is integer paisa; omitted, it is the subscription's locked price.
 */
final class RaiseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'tenant' => ['required', 'string', Rule::exists(Tenant::class, 'public_id')],
            'amount_paisa' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'issue' => ['boolean'],
        ];
    }

    public function tenant(): Tenant
    {
        return Tenant::query()->where('public_id', (string) $this->validated('tenant'))->firstOrFail();
    }

    public function amountPaisa(): ?int
    {
        $amount = $this->validated('amount_paisa');

        return $amount === null || $amount === '' ? null : (int) $amount;
    }

    public function shouldIssue(): bool
    {
        return $this->boolean('issue', true);
    }
}
