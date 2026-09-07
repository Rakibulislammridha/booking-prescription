<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\PrescriptionLanguage;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Visit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * A draft prescription for a visit (patient/doctor/branch copied from the visit). Issued rows must be produced by
 * IssuePrescription — the factory never fabricates a snapshot.
 *
 * @extends Factory<Prescription>
 */
final class PrescriptionFactory extends Factory
{
    protected $model = Prescription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'visit_id' => Visit::factory(),
            'patient_id' => fn (array $a) => Visit::query()->whereKey($a['visit_id'])->value('patient_id'),
            'doctor_id' => fn (array $a) => Visit::query()->whereKey($a['visit_id'])->value('doctor_id'),
            'branch_id' => fn (array $a) => Visit::query()->whereKey($a['visit_id'])->value('branch_id'),
            'version' => 1,
            'status' => PrescriptionStatus::Draft,
            'language' => PrescriptionLanguage::Both,
            'printed_count' => 0,
            'delivered_channels' => [],
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Prescription $rx): void {
            $visit = $rx->visit;

            if ($visit->current_prescription_id === null) {
                $visit->forceFill(['current_prescription_id' => $rx->id])->save();
            }
        });
    }
}
