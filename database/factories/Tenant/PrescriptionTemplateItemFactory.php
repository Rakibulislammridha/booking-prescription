<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\DoseTiming;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\PrescriptionTemplateItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrescriptionTemplateItem> */
final class PrescriptionTemplateItemFactory extends Factory
{
    protected $model = PrescriptionTemplateItem::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescription_template_id' => PrescriptionTemplate::factory(),
            'sort_order' => fn (array $a) => (int) PrescriptionTemplateItem::query()->where('prescription_template_id', $a['prescription_template_id'])->max('sort_order') + 1,
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
            'dose_json' => ['v' => 1, 'normalized' => '1+0+1 5d af'],
            'duration_days' => 5,
            'duration_text' => '5 days',
            'quantity' => 10,
            'quantity_unit' => 'tab',
            'timing' => DoseTiming::After,
            'instruction' => null,
            'instruction_bn' => null,
            'is_continued' => false,
        ];
    }
}
