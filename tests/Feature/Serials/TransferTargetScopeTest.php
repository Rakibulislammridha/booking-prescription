<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\User;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/**
 * A transfer has two sessions and used to authorise one of them: `transfer` on the source serial (or
 * `transferSession` on the source session), and for the target a bare `Rule::exists`. Since both actions REQUIRE
 * the target to belong to a different doctor, the unasked half was always a chamber the caller might not be
 * allowed anywhere near — so a restricted account holding `serials.transfer` could push its own doctor's patient
 * into a colleague's queue, cancel its own serial doing it, and read the new row out of the response.
 *
 * The pair of claims per path: it REFUSES a restricted caller, and it still WORKS for a doctor moving their own
 * patient — which is why the target is authorised with `view` and not `transferSession` (a doctor holds no
 * `serials.transfer`, so the stronger ability would have broken the normal case to close the leak).
 */
final class TransferTargetScopeTest extends TestCase
{
    use SerialFixtures;

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

    /** A signed-in desk user (so `serials.transfer` is held) who is also a compounder on `$mine`. */
    private function actingAsRestricted(Doctor ...$mine): User
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $user->assignRole(Role::Compounder->value);
        $user->assignedDoctors()->sync(array_map(fn (Doctor $d) => $d->id, $mine));

        return $user;
    }

    public function test_a_restricted_caller_cannot_transfer_a_serial_into_an_unseen_chamber(): void
    {
        $mine = $this->newDoctor();
        $mySession = $this->openSession(doctor: $mine);
        $theirSession = $this->openSession(doctor: $this->newDoctor());
        $serial = $this->allocate($mySession);

        $this->actingAsRestricted($mine);

        $this->postJson($this->url('panel.serials.transfer', ['serial' => $serial->public_id]), ['target_session' => $theirSession->public_id])
            ->assertStatus(403);

        $this->assertSame(SerialStatus::Booked, $serial->refresh()->status, 'the source serial was not cancelled on the way');
        $this->assertSame(0, Serial::query()->where('session_instance_id', $theirSession->id)->count());
    }

    public function test_a_restricted_caller_cannot_empty_a_session_into_an_unseen_chamber(): void
    {
        $mine = $this->newDoctor();
        $mySession = $this->openSession(doctor: $mine);
        $theirSession = $this->openSession(doctor: $this->newDoctor());
        $this->allocateMany($mySession, 3);

        $this->actingAsRestricted($mine);

        $this->postJson($this->url('panel.sessions.transfer', ['session' => $mySession->public_id]), ['target_session' => $theirSession->public_id])
            ->assertStatus(403);

        $this->assertSame(3, Serial::query()->where('session_instance_id', $mySession->id)->count());
        $this->assertSame(0, Serial::query()->where('session_instance_id', $theirSession->id)->count());
    }

    /** The case the `view` ability was chosen to preserve: a doctor hands their own patient to a colleague. */
    public function test_a_doctor_still_moves_their_own_patient_into_another_doctors_session(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $mySession = $this->openSession(doctor: $doctor);
        $theirSession = $this->openSession(doctor: $this->newDoctor());
        $serial = $this->allocate($mySession);

        $this->postJson($this->url('panel.serials.transfer', ['serial' => $serial->public_id]), ['target_session' => $theirSession->public_id])
            ->assertOk()
            ->assertJsonPath('new.session.public_id', $theirSession->public_id);

        $this->assertSame(SerialStatus::Cancelled, $serial->refresh()->status);
    }

    /** An unrestricted desk keeps both halves, and a target that does not exist is still a 422 about the field. */
    public function test_an_unrestricted_desk_transfers_freely_and_a_missing_target_is_a_validation_error(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $source = $this->openSession();
        $target = $this->openSession();
        $serial = $this->allocate($source);

        $this->postJson($this->url('panel.serials.transfer', ['serial' => $serial->public_id]), ['target_session' => $target->public_id])
            ->assertOk();

        $this->postJson($this->url('panel.serials.transfer', ['serial' => $this->allocate($source)->public_id]), ['target_session' => (string) str_repeat('0', 26)])
            ->assertStatus(422)
            ->assertJsonValidationErrors('target_session');
    }

    private function newDoctor(): Doctor
    {
        return Doctor::factory()->complete()->create();
    }
}
