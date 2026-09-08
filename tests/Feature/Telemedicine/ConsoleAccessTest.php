<?php

declare(strict_types=1);

namespace Tests\Feature\Telemedicine;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\TelemedicineRoom;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Telemedicine\Concerns\TelemedicineFixtures;
use Tests\TestCase;

/**
 * Who may watch a consultation and who may be IN it. `prescriptions.view.any` opens the record; nobody joins a
 * video call as the doctor except that doctor.
 */
final class ConsoleAccessTest extends TestCase
{
    use TelemedicineFixtures;

    private TelemedicineRoom $room;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->enableTelemedicine();
        [, $doctor] = $this->actingAsTelemedicineDoctor();
        $booking = $this->bookTelemedicine($this->openSessionFor($doctor));
        $this->room = $this->roomFor($booking->appointment->id);
    }

    public function test_the_appointments_doctor_may_consult(): void
    {
        $this->get('/panel/telemedicine/'.$this->room->room_name)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can_consult', true));

        $this->post('/panel/telemedicine/'.$this->room->room_name.'/start')->assertRedirect();
    }

    public function test_opening_the_console_writes_an_audit_view(): void
    {
        $this->get('/panel/telemedicine/'.$this->room->room_name)->assertOk();

        $this->assertAudited(AuditAction::View, $this->room, ['screen' => 'panel.telemedicine.console']);
    }

    public function test_a_hospital_admin_may_watch_but_not_join(): void
    {
        $this->flushSession();
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->get('/panel/telemedicine/'.$this->room->room_name)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can_consult', false));

        $this->post('/panel/telemedicine/'.$this->room->room_name.'/start')->assertForbidden();
        $this->postJson('/panel/telemedicine/'.$this->room->room_name.'/token')->assertForbidden();
    }

    public function test_another_doctor_can_neither_watch_nor_join(): void
    {
        $this->flushSession();
        $user = $this->actingAsStaff(Role::Doctor);
        Doctor::factory()->complete()->create(['user_id' => $user->id, 'accepts_telemedicine' => true]);

        $this->get('/panel/telemedicine/'.$this->room->room_name)->assertForbidden();
        $this->postJson('/panel/telemedicine/'.$this->room->room_name.'/token')->assertForbidden();
    }

    public function test_a_receptionist_sees_the_board_but_not_the_call(): void
    {
        $this->flushSession();
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel/telemedicine')->assertOk();
        $this->postJson('/panel/telemedicine/'.$this->room->room_name.'/token')->assertForbidden();
    }

    public function test_the_board_lists_only_rooms_the_viewer_may_see(): void
    {
        $this->flushSession();
        $user = $this->actingAsStaff(Role::Doctor);
        Doctor::factory()->complete()->create(['user_id' => $user->id, 'accepts_telemedicine' => true]);

        $this->get('/panel/telemedicine')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('rooms', 0));
    }

    public function test_an_unknown_room_name_is_a_404_before_any_query(): void
    {
        $this->get('/panel/telemedicine/not-a-room')->assertNotFound();
        $this->get('/panel/telemedicine/t9001-zzzzzzzzzzzzzzzzzzzzzzzzzz')->assertNotFound();
    }
}
