<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Patients\Enums\ConsentChannel;
use App\Domain\Patients\Enums\ConsentStatus;
use App\Domain\Patients\Enums\ConsentType;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientConsent;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PatientConsent> */
final class PatientConsentFactory extends Factory
{
    protected $model = PatientConsent::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'patient_id' => Patient::factory(),
            'type' => $this->faker->randomElement(ConsentType::cases()),
            'status' => ConsentStatus::Granted,
            'policy_version' => '2026-01',
            'channel' => $this->faker->randomElement(ConsentChannel::cases()),
            'captured_by_user_id' => null,
            'ip' => $this->faker->ipv4(),
            'user_agent' => 'PHPUnit',
            'signature_data' => null,
            'evidence' => ['otp_verified' => false, 'text_shown' => 'Consent text v2026-01'],
            'occurred_at' => now(),
        ];
    }

    public function revoked(): static
    {
        return $this->state(fn () => ['status' => ConsentStatus::Revoked]);
    }

    public function signed(): static
    {
        return $this->state(fn () => ['signature_data' => 'data:image/png;base64,iVBORw0KGgo=']);
    }
}
