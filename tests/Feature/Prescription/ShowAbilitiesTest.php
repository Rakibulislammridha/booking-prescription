<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Clinic\Actions\AssignCompounder;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * GET /panel/prescriptions/{prescription} — the `can` map the screen renders its actions from.
 *
 * The page had none: Send, Amend and Void were drawn for anyone who could open the sheet, and `view` is
 * deliberately the WIDE door (PrescriptionPolicy — `prescriptions.vitals.record` grants it so BRIEF §5.G.4's
 * printout can be handed over at the counter). A compounder therefore met three buttons that were three
 * guaranteed 403s, and a receptionist met two.
 *
 * Every case below pins the map AND then asks the endpoint behind a false flag, because a hidden button is only
 * half a claim: what makes it honest is that the server would have refused, and what makes it useful is that the
 * print path a desk exists for is untouched.
 */
final class ShowAbilitiesTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_prescribing_doctor_is_offered_every_action(): void
    {
        [$rx] = $this->issuedWithContent();

        $this->get('/panel/prescriptions/'.$rx->public_id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Prescription/Show')
                ->where('can', ['write' => true, 'send' => true, 'amend' => true, 'void' => true]));
    }

    /**
     * The compounder's copy of the same screen: the document, the print, and not one action. `send` is refused
     * even though they may read every word of the sheet — reading it at the desk and speaking to the patient in
     * the doctor's name are different acts (PrescriptionPolicy::send).
     */
    public function test_the_desk_is_offered_the_printout_and_none_of_the_three(): void
    {
        [$rx, $doctor] = $this->issuedWithContent();
        $this->compounderFor($doctor);

        $this->get('/panel/prescriptions/'.$rx->public_id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Prescription/Show')
                ->where('can', ['write' => false, 'send' => false, 'amend' => false, 'void' => false]));

        // Each false flag is the server's answer, not a cosmetic choice.
        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/send', ['channel' => 'sms'])->assertForbidden();
        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/amend', ['reason' => 'a mistake in the dose'])->assertForbidden();
        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/void', ['reason' => 'a mistake in the dose'])->assertForbidden();

        // …and the one thing the desk is on this screen for still works.
        $this->get('/panel/prescriptions/'.$rx->public_id.'/print')->assertOk();
    }

    /** A receptionist is unrestricted, so they may send the sheet — but Amend and Void are `prescriptions.write`. */
    public function test_a_receptionist_may_send_the_sheet_and_rewrite_nothing(): void
    {
        [$rx] = $this->issuedWithContent();
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel/prescriptions/'.$rx->public_id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('can', ['write' => false, 'send' => true, 'amend' => false, 'void' => false]));

        $this->postJson('/panel/prescriptions/'.$rx->public_id.'/amend', ['reason' => 'a mistake in the dose'])->assertForbidden();
    }

    /**
     * A draft opened on this screen offers exactly one way forward — into the writer — and `write` is the ability
     * WriterController itself authorises, so the button cannot lead anywhere the next request refuses.
     */
    public function test_a_draft_offers_the_writer_only_to_whoever_may_write_it(): void
    {
        [$user, $doctor, $visit] = $this->doctorWithOpenVisit();
        $draft = $this->draftFor($visit, $doctor);

        $this->actingAs($user, 'web')
            ->get('/panel/prescriptions/'.$draft->public_id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Prescription/Show')->where('can.write', true));

        $this->compounderFor($doctor);

        $this->get('/panel/prescriptions/'.$draft->public_id)
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('can.write', false));

        $this->get('/panel/visits/'.$visit->public_id.'/prescribe')->assertForbidden();
    }

    /** Signed in as a compounder on that doctor's desk, put there by the real action. */
    private function compounderFor(Doctor $doctor): User
    {
        $user = $this->actingAsStaff(Role::Compounder);
        app(AssignCompounder::class)->handle($doctor, $user, Actor::system());

        return $user;
    }
}
