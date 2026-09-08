<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine\Concerns;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\StartCall;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use App\Models\Tenant\User;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;

/**
 * The shared fixture: a clinic that HAS the add-on, a doctor who consults over video, an open session today, and
 * a real telemedicine booking made through the ordinary `BookAppointment`.
 *
 * Every one of these tests books through Booking's own action rather than inserting an appointment, because the
 * module's central claim is that a video consultation is an ordinary appointment. A fixture that shortcut the
 * booking would quietly stop testing that.
 */
trait TelemedicineFixtures
{
    use ControlsPlanLimits;

    protected function enableTelemedicine(bool $enabled = true): void
    {
        $this->setToggle($this->tenant('a'), PlanFeatureKey::Telemedicine, $enabled);
    }

    protected function mainBranch(): Branch
    {
        return Branch::query()->where('is_main', true)->firstOrFail();
    }

    /**
     * A staff user with the doctor role, logged in on `web`, plus the Doctor row that accepts video.
     *
     * @return array{0: User, 1: Doctor}
     */
    protected function actingAsTelemedicineDoctor(): array
    {
        $user = $this->actingAsStaff(Role::Doctor);
        $doctor = Doctor::factory()->complete()->create([
            'user_id' => $user->id,
            'name' => $user->name,
            'accepts_telemedicine' => true,
            'accepts_online_booking' => true,
        ]);
        $doctor->profile?->fill(['new_fee_paisa' => 80000, 'followup_fee_paisa' => 40000, 'telemedicine_fee_paisa' => 60000])->save();
        $doctor->unsetRelation('profile');

        return [$user, $doctor];
    }

    protected function openSessionFor(Doctor $doctor, int $counter = 10, int $online = 10, int $buffer = 5): SessionInstance
    {
        return SessionInstance::factory()->openToday()->quotas($counter, $online, $buffer)->create([
            'doctor_id' => $doctor->id,
            'branch_id' => $this->mainBranch()->id,
        ]);
    }

    /** The ordinary booking flow with `BookingChannel::Telemedicine` — nothing else differs. */
    protected function bookTelemedicine(SessionInstance $session, string $mobile = '01711111111', string $name = 'Rahima Begum'): BookingResult
    {
        return app(BookAppointment::class)->handle(
            new BookingRequest(
                channel: BookingChannel::Telemedicine,
                mobile: $mobile,
                name: $name,
                ageYears: 34,
                sessionPublicId: $session->public_id,
                otpVerified: true,
            ),
            Actor::system(),
        );
    }

    protected function roomFor(int $appointmentId): TelemedicineRoom
    {
        return TelemedicineRoom::query()->where('appointment_id', $appointmentId)->firstOrFail();
    }

    protected function startCall(TelemedicineRoom $room, ?Actor $actor = null): TelemedicineSession
    {
        return app(StartCall::class)->handle($room, $actor ?? Actor::user(1, 'doctor'));
    }
}
