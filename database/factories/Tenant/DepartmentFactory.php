<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Department> */
final class DepartmentFactory extends Factory
{
    protected $model = Department::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1, 9999);
        [$en, $bn] = $this->faker->randomElement([['Medicine', 'মেডিসিন'], ['Surgery', 'সার্জারি'], ['Paediatrics', 'শিশু বিভাগ'], ['Gynaecology', 'গাইনী']]);

        return [
            'branch_id' => null,
            'name' => $en,
            'name_bn' => $bn,
            'slug' => 'dept-'.$n,
            'sort_order' => 0,
            'is_active' => true,
        ];
    }
}
