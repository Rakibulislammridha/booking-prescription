<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Services\Paisa;
use App\Models\Tenant\Coupon;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Coupon> */
final class CouponFactory extends Factory
{
    protected $model = Coupon::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'code' => 'BP'.mb_strtoupper(Str::random(6)),
            'name' => 'Launch offer',
            'type' => DiscountType::Percentage,
            'value' => '10.00',
            'max_discount_paisa' => null,
            'min_invoice_paisa' => 0,
            'max_uses' => null,
            'max_uses_per_patient' => 1,
            'uses_count' => 0,
            'applies_to' => [],
            'valid_from' => null,
            'valid_until' => null,
            'is_active' => true,
        ];
    }

    /** A flat coupon, given in paisa; the column stores it as the taka decimal every `value` column uses. */
    public function fixed(int $paisa): static
    {
        return $this->state(fn () => ['type' => DiscountType::Fixed, 'value' => Paisa::toDecimal($paisa)]);
    }
}
