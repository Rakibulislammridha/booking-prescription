<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Billing\Enums\CashShiftStatus;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashShift;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CashShift> */
final class CashShiftFactory extends Factory
{
    protected $model = CashShift::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'branch_id' => Branch::factory(),
            'status' => CashShiftStatus::Open,
            'opened_at' => now(),
            'opening_float_paisa' => 100000,
        ];
    }

    public function closed(int $expected = 0, int $counted = 0): static
    {
        return $this->state(fn () => [
            'status' => CashShiftStatus::Closed,
            'closed_at' => now(),
            'expected_cash_paisa' => $expected,
            'counted_cash_paisa' => $counted,
            'variance_paisa' => $counted - $expected,
        ]);
    }
}
