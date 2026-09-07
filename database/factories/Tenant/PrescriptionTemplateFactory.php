<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\PrescriptionTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrescriptionTemplate> */
final class PrescriptionTemplateFactory extends Factory
{
    protected $model = PrescriptionTemplate::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory()->complete(),
            'name' => 'Common cold – adult '.$this->faker->unique()->numberBetween(1, 99999),
            'shorthand' => null,
            'icd10_code' => 'J06.9',
            'diagnosis_title' => 'Acute upper respiratory infection, unspecified',
            'is_shared' => false,
            'body' => [
                'chief_complaints' => [['text' => 'Fever', 'text_bn' => null, 'duration' => '3d', 'sort' => 0]],
                'examination_findings' => null,
                'advice' => [['snippet_id' => null, 'text' => 'Rest', 'text_bn' => 'বিশ্রাম']],
                'investigations' => [],
                'follow_up_days' => 7,
            ],
            'use_count' => 0,
        ];
    }

    public function shared(): static
    {
        return $this->state(fn () => ['doctor_id' => null, 'is_shared' => true]);
    }
}
