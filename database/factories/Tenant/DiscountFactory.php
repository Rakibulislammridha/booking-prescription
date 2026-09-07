<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\DiscountReason;
use App\Domain\Billing\Enums\DiscountType;
use App\Models\Tenant\Discount;
use App\Models\Tenant\Invoice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Discount> */
final class DiscountFactory extends Factory
{
    protected $model = Discount::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'invoice_id' => Invoice::factory(),
            'type' => DiscountType::Fixed,
            'value' => '100.00',
            'amount_paisa' => 10000,
            'reason_code' => DiscountReason::Staff,
        ];
    }
}
