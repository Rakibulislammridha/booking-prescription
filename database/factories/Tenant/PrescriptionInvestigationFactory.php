<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionInvestigation;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrescriptionInvestigation> */
final class PrescriptionInvestigationFactory extends Factory
{
    protected $model = PrescriptionInvestigation::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'sort_order' => fn (array $a) => (int) PrescriptionInvestigation::query()->where('prescription_id', $a['prescription_id'])->max('sort_order') + 1,
            'investigation_catalog_id' => null,
            'name' => 'CBC',
            'name_bn' => 'সিবিসি',
            'price_paisa' => 40000,
            'external_diagnostic_centre_id' => null,
            'referral_note' => null,
            'is_urgent' => false,
        ];
    }
}
