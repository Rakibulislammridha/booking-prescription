<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Prescription\Enums\ReferralType;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionReferral;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PrescriptionReferral> */
final class PrescriptionReferralFactory extends Factory
{
    protected $model = PrescriptionReferral::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'prescription_id' => Prescription::factory(),
            'type' => ReferralType::Doctor,
            'referred_to_doctor_id' => null,
            'external_diagnostic_centre_id' => null,
            'referred_to_name' => 'Dr. Cardiologist',
            'referred_to_specialty' => 'Cardiology',
            'note' => 'Please evaluate chest pain',
            'is_urgent' => false,
        ];
    }
}
