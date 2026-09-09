<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Actions\OpenVisitForVitals;
use App\Domain\Prescription\Exceptions\SerialNotPresent;
use App\Domain\Serials\Actions\CallSerial;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * The desk's vitals entry is scoped, not merely permissioned (BRIEF §5.G.2, §5.N "role-scoped data access"):
 * `SerialPolicy::recordVitals` wants the serial at a branch the user acts for AND a patient who is in the building,
 * and `OpenVisitForVitals` refuses the latter on its own, so no HTTP path — and no future caller — can open a
 * clinical encounter for someone who has not arrived, or for a row at a branch this desk does not work.
 */
final class DeskVitalsScopeTest extends TestCase
{
    use BillingFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    private function sessionAt(Branch $branch): SessionInstance
    {
        return SessionInstance::factory()->openToday()->quotas(10, 10, 5)->create([
            'doctor_id' => Doctor::factory()->complete()->create()->id,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_a_receptionist_cannot_open_an_encounter_for_a_serial_at_another_branch(): void
    {
        $receptionist = $this->actingAsStaff(Role::Receptionist);   // active branch = main
        $otherBranch = Branch::factory()->create(['is_main' => false]);
        $serial = Serial::query()->findOrFail($this->book($this->sessionAt($otherBranch), mobile: '01710000321', name: 'Elsewhere')->appointment->serial_id);

        // Even checked in over there, the row is not this desk's to act on.
        $this->post($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk();
        $this->assertSame(SerialStatus::CheckedIn, $serial->refresh()->status);
        $this->assertFalse(Gate::forUser($receptionist)->allows('recordVitals', $serial));

        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertForbidden();

        $this->assertNull(Visit::query()->where('serial_id', $serial->id)->first());
        $this->assertSame(0, (int) Patient::query()->whereKey($serial->patient_id)->value('visit_count'));

        // The same person working that branch (switched to it) may.
        $this->actingAsStaff(Role::Receptionist, $otherBranch);
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertRedirect();
        $this->assertNotNull(Visit::query()->where('serial_id', $serial->id)->first());
    }

    public function test_a_booked_serial_that_has_not_been_checked_in_is_refused_and_no_visit_is_created(): void
    {
        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $serial = Serial::query()->findOrFail($this->book($this->sessionAt($this->mainBranch()), mobile: '01710000322', name: 'Not Here Yet')->appointment->serial_id);
        $this->assertSame(SerialStatus::Booked, $serial->status, 'precondition: not checked in');
        $visitsBefore = (int) Patient::query()->whereKey($serial->patient_id)->value('visit_count');

        $this->assertFalse(Gate::forUser($receptionist)->allows('recordVitals', $serial));
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertForbidden();

        $this->assertNull(Visit::query()->where('serial_id', $serial->id)->first(), 'no encounter for a patient who has not arrived');
        $this->assertSame($visitsBefore, (int) Patient::query()->whereKey($serial->patient_id)->value('visit_count'));
        $this->assertSame(SerialStatus::Booked, $serial->refresh()->status);
    }

    /** The domain rule stands on its own: the action refuses before StartVisit is asked for anything. */
    public function test_the_desk_action_itself_refuses_a_patient_who_is_not_present(): void
    {
        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $serial = Serial::query()->findOrFail($this->book($this->sessionAt($this->mainBranch()), mobile: '01710000323', name: 'Booked Only')->appointment->serial_id);
        $actor = Actor::user((int) $receptionist->id, Role::Receptionist->value);

        try {
            app(OpenVisitForVitals::class)->handle($serial, $actor);
            $this->fail('a booked serial must not open an encounter');
        } catch (SerialNotPresent $e) {
            $this->assertSame('prescriptions.serial_not_present', $e->code());
            $this->assertSame(409, $e->status());
        }

        $this->assertNull(Visit::query()->where('serial_id', $serial->id)->first());
        $this->assertSame(0, (int) Patient::query()->whereKey($serial->patient_id)->value('visit_count'));
        $this->assertNull(Patient::query()->whereKey($serial->patient_id)->value('last_visit_at'));

        // Checked in, the same call opens the visit — and opens the same one again.
        $this->post($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk();
        $visit = app(OpenVisitForVitals::class)->handle($serial->refresh(), $actor);
        $this->assertSame($visit->id, app(OpenVisitForVitals::class)->handle($serial, $actor)->id);
        $this->assertSame(1, (int) Patient::query()->whereKey($serial->patient_id)->value('visit_count'));
    }

    public function test_a_checked_in_serial_at_the_desks_own_branch_opens_the_vitals_screen(): void
    {
        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $serial = Serial::query()->findOrFail($this->book($this->sessionAt($this->mainBranch()), mobile: '01710000324', name: 'Arrived')->appointment->serial_id);

        $this->post($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk();
        $this->assertTrue(Gate::forUser($receptionist)->allows('recordVitals', $serial->refresh()));

        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))
            ->assertRedirect();

        $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();
        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $visit->public_id]))->assertOk();
    }

    /** The doctor-side path is untouched: called (→ in consultation) still opens the encounter, and the desk may add vitals to it. */
    public function test_a_serial_in_the_chamber_is_still_within_the_desks_reach(): void
    {
        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $serial = Serial::query()->findOrFail($this->book($this->sessionAt($this->mainBranch()), mobile: '01710000325', name: 'In Chamber')->appointment->serial_id);

        app(CallSerial::class)->handle($serial, Actor::user((int) $receptionist->id, Role::Receptionist->value));
        $this->assertSame(SerialStatus::InConsultation, $serial->refresh()->status);
        $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();   // StartVisitOnSerialCalled

        $this->assertTrue(Gate::forUser($receptionist)->allows('recordVitals', $serial));
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))
            ->assertRedirect($this->url('panel.reception.vitals.edit', ['visit' => $visit->public_id]));
        $this->assertSame(1, Visit::query()->where('serial_id', $serial->id)->count());
    }

    public function test_a_hospital_admin_acts_for_every_branch(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);   // default branch = main
        $otherBranch = Branch::factory()->create(['is_main' => false]);
        $serial = Serial::query()->findOrFail($this->book($this->sessionAt($otherBranch), mobile: '01710000326', name: 'Admin Reach')->appointment->serial_id);
        $this->post($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk();

        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertRedirect();
        $this->assertNotNull(Visit::query()->where('serial_id', $serial->id)->first());
    }
}
