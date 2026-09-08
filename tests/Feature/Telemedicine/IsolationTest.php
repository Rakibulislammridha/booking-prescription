<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\Telemedicine\Services\RoomName;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/** BRIEF §8 row 5: no query from one clinic can reach another clinic's consultations. */
final class IsolationTest extends TestCase
{
    use TelemedicineFixtures;

    public function test_telemedicine_rooms_are_invisible_to_another_tenant(): void
    {
        $this->assertTenantIsolated('telemedicine_rooms', function (): void {
            $this->enableTelemedicine();
            [, $doctor] = $this->actingAsTelemedicineDoctor();
            $this->bookTelemedicine($this->openSessionFor($doctor));
        });
    }

    public function test_telemedicine_sessions_are_invisible_to_another_tenant(): void
    {
        $this->assertTenantIsolated('telemedicine_sessions', function (): void {
            $this->enableTelemedicine();
            [, $doctor] = $this->actingAsTelemedicineDoctor();
            $booking = $this->bookTelemedicine($this->openSessionFor($doctor));
            $this->startCall($this->roomFor($booking->appointment->id));
        });
    }

    public function test_the_models_refuse_to_run_without_tenancy(): void
    {
        Tenancy::check() && Tenancy::end();

        $this->assertThrows(fn () => TelemedicineRoom::query()->count(), TenancyNotInitialized::class);
        $this->assertThrows(fn () => TelemedicineSession::query()->count(), TenancyNotInitialized::class);
    }

    public function test_a_room_name_carries_its_tenant_and_is_unique(): void
    {
        $this->asTenant('a');
        $a = RoomName::generate();
        $this->assertStringStartsWith('t9001-', $a);
        $this->assertNotSame($a, RoomName::generate());

        $this->asTenant('b');
        $this->assertStringStartsWith('t9002-', RoomName::generate());
    }

    public function test_one_clinics_doctor_cannot_open_another_clinics_room(): void
    {
        $this->asTenant('a');
        $this->enableTelemedicine();
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $booking = $this->bookTelemedicine($this->openSessionFor($doctor));
        $roomName = $this->roomFor($booking->appointment->id)->room_name;

        $this->asTenant('b');
        $this->setToggle($this->tenant('b'), PlanFeatureKey::Telemedicine, true);
        $this->actingAsTelemedicineDoctor();

        $this->get('/panel/telemedicine/'.$roomName)->assertNotFound();
    }
}
