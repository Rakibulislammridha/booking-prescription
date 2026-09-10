<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Billing;

use App\Domain\SaaS\Enums\BillingCycle;
use App\Models\Central\Plan;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Move a clinic to another plan — entitlements now, price at the next renewal (`ChangePlan`'s no-proration rule). */
final class ChangePlanRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'plan' => ['required', 'string', Rule::exists(Plan::class, 'code')],
            'billing_cycle' => ['nullable', Rule::in(BillingCycle::values())],
        ];
    }

    public function plan(): Plan
    {
        return Plan::query()->where('code', (string) $this->validated('plan'))->firstOrFail();
    }

    public function cycle(): ?BillingCycle
    {
        $cycle = $this->validated('billing_cycle');

        return $cycle === null || $cycle === '' ? null : BillingCycle::from((string) $cycle);
    }
}
