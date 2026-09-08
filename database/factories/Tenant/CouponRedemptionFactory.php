<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Coupon;
use App\Models\Tenant\CouponRedemption;
use App\Models\Tenant\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CouponRedemption> */
final class CouponRedemptionFactory extends Factory
{
    protected $model = CouponRedemption::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'coupon_id' => Coupon::factory(),
            'invoice_id' => Invoice::factory(),
            'patient_id' => fn (array $a) => (int) Invoice::query()->whereKey($a['invoice_id'])->value('patient_id'),
            'amount_paisa' => 5000,
            // The ordinals ApplyCoupon assigns under the coupons row lock; a factory row is the first use of its
            // coupon unless a state says otherwise (the unique indexes will say so loudly if it is not).
            'coupon_use_seq' => 1,
            'patient_use_seq' => 1,
        ];
    }
}
