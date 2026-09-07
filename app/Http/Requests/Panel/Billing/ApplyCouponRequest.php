<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Billing;

use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;

final class ApplyCouponRequest extends FormRequest
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
        return ['code' => ['required', 'string', 'max:32']];
    }
}
