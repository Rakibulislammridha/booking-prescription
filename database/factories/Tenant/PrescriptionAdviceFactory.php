<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionAdvice;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrescriptionAdvice> */
final class PrescriptionAdviceFactory extends Factory
{
    protected $model = PrescriptionAdvice::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'sort_order' => fn (array $a) => (int) PrescriptionAdvice::query()->where('prescription_id', $a['prescription_id'])->max('sort_order') + 1,
            'advice_snippet_id' => null,
            'text' => 'Drink plenty of water',
            'text_bn' => 'প্রচুর পানি পান করুন',
        ];
    }
}
