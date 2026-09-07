<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Holiday;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Holiday> */
final class HolidayFactory extends Factory
{
    protected $model = Holiday::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'branch_id' => null,
            'holiday_date' => now()->addDays($this->faker->unique()->numberBetween(1, 3000))->toDateString(),
            'name' => 'Victory Day',
            'name_bn' => 'বিজয় দিবস',
        ];
    }
}
