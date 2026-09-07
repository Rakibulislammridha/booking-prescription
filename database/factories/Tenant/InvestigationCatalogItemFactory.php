<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\InvestigationCategory;
use App\Models\Tenant\InvestigationCatalogItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<InvestigationCatalogItem> */
final class InvestigationCatalogItemFactory extends Factory
{
    protected $model = InvestigationCatalogItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1, 99999);

        return [
            'branch_id' => null,
            'code' => 'T'.$n,
            'name' => 'CBC '.$n,
            'name_bn' => 'সিবিসি',
            'category' => InvestigationCategory::Lab,
            'price_paisa' => 40000,
            'prep_instructions' => null,
            'prep_instructions_bn' => null,
            'is_active' => true,
            'sort_order' => 0,
        ];
    }
}
