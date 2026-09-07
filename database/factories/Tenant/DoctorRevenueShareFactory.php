<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\RevenueShareItemType;
use App\Domain\Billing\Enums\RevenueShareType;
use App\Domain\Billing\Services\Paisa;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorRevenueShare;
use App\Support\Clock;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorRevenueShare> */
final class DoctorRevenueShareFactory extends Factory
{
    protected $model = DoctorRevenueShare::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory(),
            'branch_id' => null,
            'item_type' => RevenueShareItemType::All,
            'share_type' => RevenueShareType::Percentage,
            'share_value' => '60.00',
            'effective_from' => Clock::today()->subYear()->toDateString(),
            'effective_to' => null,
            'is_active' => true,
        ];
    }

    public function percentage(float $percent): static
    {
        return $this->state(fn () => ['share_type' => RevenueShareType::Percentage, 'share_value' => number_format($percent, 2, '.', '')]);
    }

    /** A flat share, given in paisa; the column stores it as the taka decimal every `value` column uses. */
    public function fixed(int $paisa): static
    {
        return $this->state(fn () => ['share_type' => RevenueShareType::Fixed, 'share_value' => Paisa::toDecimal($paisa)]);
    }
}
