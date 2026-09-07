<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\AdviceCategory;
use App\Models\Tenant\AdviceSnippet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A clinic-library (doctor_id null) advice snippet.
 *
 * @extends Factory<AdviceSnippet>
 */
final class AdviceSnippetFactory extends Factory
{
    protected $model = AdviceSnippet::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => null,
            'shorthand' => null,
            'category' => AdviceCategory::General,
            'text' => 'Drink plenty of water',
            'text_bn' => 'প্রচুর পানি পান করুন',
            'is_shared' => true,
            'use_count' => 0,
            'is_active' => true,
        ];
    }

    public function finding(): static
    {
        return $this->state(fn () => ['category' => AdviceCategory::Finding, 'text' => 'Throat congested', 'text_bn' => 'গলা লাল']);
    }
}
