<?php

declare(strict_types=1);

namespace Database\Factories\Catalog;

use App\Domain\Catalog\Enums\CustomBrandReviewStatus;
use App\Models\Tenant\CustomBrand;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;

/**
 * Tenant custom brand; assumes tenancy is active. The generic defaults to the first active seed generic.
 *
 * @extends Factory<CustomBrand>
 */
final class CustomBrandFactory extends Factory
{
    protected $model = CustomBrand::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $generic = (array) DB::connection('catalog')->table('generics')->where('is_active', true)->orderBy('id')->first(['id', 'name']);
        $form = (array) DB::connection('catalog')->table('dosage_forms')->where('code', 'tab')->first(['id', 'name', 'default_route_id']);
        $route = isset($form['default_route_id']) ? (array) DB::connection('catalog')->table('routes')->find($form['default_route_id'], ['id', 'name']) : [];

        return [
            'generic_id' => (int) ($generic['id'] ?? 1),
            'generic_name' => (string) ($generic['name'] ?? 'Paracetamol'),
            'brand_name' => ucfirst($this->faker->unique()->lexify('??????')).'-X',
            'manufacturer' => $this->faker->randomElement(['Local Pharma', 'Dhaka Labs', 'Chittagong Chemicals']),
            'strength' => '500 mg',
            'dosage_form_id' => isset($form['id']) ? (int) $form['id'] : null,
            'form' => (string) ($form['name'] ?? 'Tablet'),
            'route_id' => isset($route['id']) ? (int) $route['id'] : null,
            'route' => (string) ($route['name'] ?? 'Oral'),
            'review_status' => CustomBrandReviewStatus::Pending,
            'promoted_to_master' => false,
            'use_count' => 0,
            'is_active' => true,
        ];
    }

    public function rejected(): self
    {
        return $this->state(['review_status' => CustomBrandReviewStatus::Rejected, 'reviewed_at' => now(), 'review_note' => 'duplicate of a master brand']);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
