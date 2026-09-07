<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\DoseTiming;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A snapshotted paracetamol line (soft ids null unless given).
 *
 * @extends Factory<PrescriptionItem>
 */
final class PrescriptionItemFactory extends Factory
{
    protected $model = PrescriptionItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'sort_order' => fn (array $a) => (int) PrescriptionItem::query()->where('prescription_id', $a['prescription_id'])->max('sort_order') + 1,
            'generic_id' => null,
            'brand_id' => null,
            'strength_id' => null,
            'custom_brand_id' => null,
            'generic_name' => 'Paracetamol',
            'brand_name' => 'Napa',
            'strength' => '500 mg',
            'form' => 'Tablet',
            'route' => 'Oral',
            'dose_schedule' => '1+0+1',
            'dose_json' => [],
            'duration_days' => 5,
            'duration_text' => '5 days',
            'quantity' => 10,
            'quantity_unit' => 'tab',
            'timing' => DoseTiming::After,
            'instruction' => null,
            'instruction_bn' => null,
            'info_url_slug' => 'paracetamol',
            'is_continued' => false,
            'safety_overrides' => [],
        ];
    }
}
