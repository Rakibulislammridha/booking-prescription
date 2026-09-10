<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Billing;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspend, reactivate and cancel from the billing desk. Each takes a typed reason (what the audit row and the
 * support desk will read back); `immediately` is cancel's "now, not at period end", `force` is reactivate's
 * "even with unpaid invoices".
 */
final class ReasonedActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
            'immediately' => ['boolean'],
            'force' => ['boolean'],
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }

    public function immediately(): bool
    {
        return $this->boolean('immediately');
    }

    public function force(): bool
    {
        return $this->boolean('force');
    }
}
