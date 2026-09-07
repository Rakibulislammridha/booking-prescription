<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Clinic\Enums\Gender;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\DoctorProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Doctor> */
final class DoctorFactory extends Factory
{
    protected $model = Doctor::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $n = $this->faker->unique()->numberBetween(1, 9999);
        [$en, $bn] = $this->faker->randomElement([['Dr. Rahman', 'ডা. রহমান'], ['Dr. Sultana', 'ডা. সুলতানা'], ['Dr. Karim', 'ডা. করিম'], ['Dr. Akter', 'ডা. আক্তার']]);

        return [
            'user_id' => null,
            'name' => $en,
            'name_bn' => $bn,
            'slug' => 'dr-'.$n,
            'code' => 'D'.str_pad((string) $n, 4, '0', STR_PAD_LEFT),
            'gender' => $this->faker->randomElement(Gender::cases()),
            'mobile' => '+88017'.$this->faker->numerify('########'),
            'is_active' => true,
            'accepts_online_booking' => true,
            'accepts_telemedicine' => false,
            'sort_order' => 0,
            'room_label' => 'Room '.$this->faker->numberBetween(1, 9),
        ];
    }

    /** Doctor with a profile (fees) and default pad settings — the shape CreateDoctor produces. */
    public function complete(): static
    {
        return $this->afterCreating(function (Doctor $doctor): void {
            DoctorProfile::factory()->for($doctor)->create();
            DoctorPadSetting::factory()->for($doctor)->create();
        });
    }
}
