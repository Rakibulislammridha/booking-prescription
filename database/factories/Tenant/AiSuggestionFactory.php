<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\AiSuggestionType;
use App\Models\Tenant\AiSuggestion;
use App\Models\Tenant\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiSuggestion> */
final class AiSuggestionFactory extends Factory
{
    protected $model = AiSuggestion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'visit_id' => Visit::factory(),
            'doctor_id' => fn (array $a) => Visit::query()->whereKey($a['visit_id'])->value('doctor_id'),
            'patient_id' => fn (array $a) => Visit::query()->whereKey($a['visit_id'])->value('patient_id'),
            'type' => AiSuggestionType::HistorySummary,
            'provider' => 'null',
            'model' => 'none',
            'prompt' => 'summary.v1',
            'response' => json_encode(['lines' => ['No history.', '', '']], JSON_THROW_ON_ERROR),
            'accepted' => null,
            'created_at' => now(),
        ];
    }
}
