<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Billing;

use App\Domain\Billing\Enums\DiscountType;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();
        $coupon = $this->route('coupon');

        return $user instanceof User && ($coupon instanceof Coupon ? $user->can('update', $coupon) : $user->can('create', Coupon::class));
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $coupon = $this->route('coupon');

        return [
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Za-z0-9_-]+$/', Rule::unique('coupons', 'code')->ignore($coupon instanceof Coupon ? $coupon->id : null)],
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in(DiscountType::values())],
            'value' => ['required', 'numeric', 'min:0', 'max:99999999.99'],
            'max_discount_paisa' => ['nullable', 'integer', 'min:0'],
            'min_invoice_paisa' => ['nullable', 'integer', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:0'],
            'max_uses_per_patient' => ['nullable', 'integer', 'min:1', 'max:32767'],
            'applies_to' => ['nullable', 'array'],
            'applies_to.doctor_ids' => ['nullable', 'array'],
            'applies_to.branch_ids' => ['nullable', 'array'],
            'applies_to.item_types' => ['nullable', 'array'],
            'applies_to.channels' => ['nullable', 'array'],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['nullable', 'boolean'],
        ];
    }
}
