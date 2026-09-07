<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Patients\Enums\OtpChannel;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Models\Tenant\PatientOtpCode;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/** @extends Factory<PatientOtpCode> */
final class PatientOtpCodeFactory extends Factory
{
    protected $model = PatientOtpCode::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'mobile' => '+88017'.$this->faker->numerify('########'),
            'patient_id' => null,
            'purpose' => OtpPurpose::Login,
            'code_hash' => Hash::make('123456'),
            'channel' => OtpChannel::Sms,
            'attempts' => 0,
            'expires_at' => now()->addMinutes(5),
            'consumed_at' => null,
            'ip' => $this->faker->ipv4(),
        ];
    }

    public function forCode(string $code): static
    {
        return $this->state(fn () => ['code_hash' => Hash::make($code)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function consumed(): static
    {
        return $this->state(fn () => ['consumed_at' => now()]);
    }
}
