<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** The JSON endpoints R's board calls (routes/panel/serials.php), the Inertia pages, policies and the public availability API. */
final class SerialHttpTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /**
     * Relative URL (no scheme/host) so TestCase::prepareUrlForRequest() sends it to the tenant host of asTenant().
     *
     * @param  array<string, mixed>  $params
     */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    public function test_reception_issues_checks_in_calls_and_reorders_through_the_panel_json_endpoints(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $session = $this->openSession(10, 10, 5);

        $issued = $this->postJson($this->url('panel.sessions.serials.store', ['session' => $session->public_id]), ['source' => 'counter'])
            ->assertCreated()
            ->assertJsonPath('data.display_code', 'A-001')
            ->assertJsonPath('data.status', 'booked')
            ->assertJsonPath('data.session.code', 'A');
        $walkin = $this->postJson($this->url('panel.sessions.serials.store', ['session' => $session->public_id]), ['source' => 'walkin', 'priority' => 'emergency'])
            ->assertCreated()->assertJsonPath('data.pool', 'buffer')->assertJsonPath('data.priority', 'emergency');
        $second = $this->postJson($this->url('panel.sessions.serials.store', ['session' => $session->public_id]), ['source' => 'counter'])->assertCreated();

        $a = (string) $issued->json('data.public_id');
        $b = (string) $second->json('data.public_id');
        $e = (string) $walkin->json('data.public_id');

        $this->postJson($this->url('panel.serials.check-in', ['serial' => $a]))->assertOk()->assertJsonPath('serial.status', 'checked_in');
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $a]))->assertStatus(409)->assertJsonPath('code', 'serials.illegal_transition');

        $this->postJson($this->url('panel.sessions.call-next', ['session' => $session->public_id]))->assertOk()
            ->assertJsonPath('called.public_id', $a)->assertJsonPath('called.status', 'in_consultation')->assertJsonPath('waiting_booked', 2);
        $this->getJson($this->url('panel.sessions.show', ['session' => $session->public_id]))->assertOk()
            ->assertJsonPath('session.status', 'running')->assertJsonPath('session.counts.in_consultation', 1)->assertJsonPath('session.remaining.counter', 8)->assertJsonCount(3, 'session.serials');
        $this->getJson($this->url('panel.sessions.capacity', ['session' => $session->public_id]))->assertOk()->assertJsonPath('buffer', 4);

        // queue order is e (emergency, head), a (in consultation), b: move b between e and a; the reversed pair is stale
        $this->postJson($this->url('panel.serials.reorder', ['serial' => $b]), ['after' => $e, 'before' => $a, 'reason' => 'came first'])->assertOk()->assertJsonPath('serial.public_id', $b);
        $this->postJson($this->url('panel.serials.reorder', ['serial' => $b]), ['after' => $a, 'before' => $e])->assertStatus(409)->assertJsonPath('code', 'serials.reorder_stale');
        $this->postJson($this->url('panel.serials.reorder', ['serial' => $b]), [])->assertStatus(409);

        $this->postJson($this->url('panel.serials.complete', ['serial' => $a]))->assertOk()->assertJsonPath('serial.status', 'completed');
        $this->postJson($this->url('panel.serials.cancel', ['serial' => $e]), ['reason_code' => 'patient_request'])->assertOk()->assertJsonPath('refund_eligible', true);
        $this->postJson($this->url('panel.serials.cancel', ['serial' => $e]), ['reason_code' => 'transferred'])->assertStatus(422);
        $this->postJson($this->url('panel.serials.no-show', ['serial' => $b]))->assertOk()->assertJsonPath('serial.status', 'no_show');
        $this->postJson($this->url('panel.serials.reinstate', ['serial' => $b]), ['present' => false])->assertOk()->assertJsonPath('serial.status', 'booked');
        $this->postJson($this->url('panel.serials.priority', ['serial' => $b]), ['priority' => 'vip'])->assertStatus(422)->assertJsonPath('code', 'serials.reason_required');

        $this->postJson($this->url('panel.sessions.extend', ['session' => $session->public_id]), ['extra' => 3])->assertStatus(403);   // receptionist limit 0
        $this->postJson($this->url('panel.sessions.delay', ['session' => $session->public_id]), ['delay_minutes' => 20])->assertStatus(403);
        $this->putJson($this->url('panel.sessions.pools.split', ['session' => $session->public_id]), ['counter_quota' => 5, 'online_quota' => 15])->assertStatus(403);
        $this->postJson($this->url('panel.sessions.close', ['session' => $session->public_id]))->assertOk()->assertJsonPath('session.status', 'closed');
        $this->postJson($this->url('panel.sessions.serials.store', ['session' => $session->public_id]), ['source' => 'counter'])->assertStatus(409)->assertJsonPath('code', 'serials.session_not_accepting');
    }

    public function test_doctor_lifecycle_split_and_release_endpoints(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->openSession(10, 10, 5, $doctor);

        $this->postJson($this->url('panel.sessions.start', ['session' => $session->public_id]))->assertOk()->assertJsonPath('session.status', 'running');
        $this->postJson($this->url('panel.sessions.pause', ['session' => $session->public_id]), ['reason' => 'prayer'])->assertOk()->assertJsonPath('session.status', 'paused');
        $this->postJson($this->url('panel.sessions.resume', ['session' => $session->public_id]))->assertOk()->assertJsonPath('session.status', 'running');
        $this->postJson($this->url('panel.sessions.delay', ['session' => $session->public_id]), ['delay_minutes' => 25, 'message' => 'late'])->assertOk()->assertJsonPath('session.delay_minutes', 25);
        $this->postJson($this->url('panel.sessions.extend', ['session' => $session->public_id]), ['extra' => 4])->assertOk()->assertJsonPath('session.max_serials', 29)->assertJsonPath('pools.buffer.range_end', 29);
        $this->putJson($this->url('panel.sessions.pools.split', ['session' => $session->public_id]), ['counter_quota' => 12, 'online_quota' => 8])->assertOk()->assertJsonPath('pools.counter.range_end', 12);
        $this->postJson($this->url('panel.sessions.pools.release-online', ['session' => $session->public_id]), ['count' => 3])->assertOk()
            ->assertJsonPath('released_block.range_start', 18)->assertJsonPath('released_block.status', 'released')->assertJsonPath('pools.online.range_end', 17);
        $this->putJson($this->url('panel.sessions.pools.split', ['session' => $session->public_id]), ['counter_quota' => 12, 'online_quota' => 8])->assertStatus(409)->assertJsonPath('code', 'serials.split_locked');

        $other = $this->openSession(10, 10, 5);
        $this->postJson($this->url('panel.sessions.start', ['session' => $other->public_id]))->assertStatus(403);
        $this->postJson($this->url('panel.sessions.cancel', ['session' => $session->public_id]), ['reason' => 'sick'])->assertOk()->assertJsonPath('session.status', 'cancelled');
    }

    public function test_postpone_and_transfer_endpoints(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $doctor = Doctor::factory()->complete()->create();
        $morning = $this->openSession(10, 10, 5, $doctor);
        $evening = SessionInstance::factory()->on($this->today(), 'B', '17:00', '21:00')->quotas(10, 10, 5)->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id]);
        $other = $this->openSession();
        $serial = $this->allocate($morning);
        $second = $this->allocate($morning);

        $this->postJson($this->url('panel.serials.postpone', ['serial' => $serial->public_id]), ['reason' => 'late'])->assertOk()
            ->assertJsonPath('old.status', 'postponed')->assertJsonPath('new.session.code', 'B');
        $this->postJson($this->url('panel.serials.transfer', ['serial' => $second->public_id]), ['target_session' => $other->public_id, 'reason' => 'unavailable'])->assertOk()
            ->assertJsonPath('old.cancel_reason_code', 'transferred')->assertJsonPath('fee_delta_expected', 0);
        $this->postJson($this->url('panel.serials.transfer', ['serial' => $this->allocate($morning)->public_id]), ['target_session' => $evening->public_id])->assertStatus(409)->assertJsonPath('code', 'serials.transfer_target_invalid');
        $this->postJson($this->url('panel.sessions.transfer', ['session' => $morning->public_id]), ['target_session' => $other->public_id])->assertOk()->assertJsonPath('remaining', 0);
    }

    public function test_scheduling_pages_and_template_crud(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $doctor = Doctor::factory()->complete()->create();
        $branch = $this->mainBranch();

        $this->get($this->url('panel.scheduling.index', ['doctor' => $doctor->id, 'branch' => $branch->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Scheduling/Index')->where('selected.doctor_id', $doctor->id)->has('schedules', 0)->where('can_manage', true));

        $this->post($this->url('panel.scheduling.schedules.store'), [
            'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'weekday' => 2, 'session_code' => 'a', 'start_time' => '09:00', 'end_time' => '13:00',
            'counter_quota' => 10, 'online_quota' => 10, 'buffer_quota' => 5,
        ])->assertRedirect($this->url('panel.scheduling.index', ['doctor' => $doctor->id, 'branch' => $branch->id]));
        $schedule = DoctorSchedule::query()->where('doctor_id', $doctor->id)->firstOrFail();
        $this->assertSame('A', $schedule->session_code);
        $this->assertSame(25, $schedule->max_serials);

        $this->post($this->url('panel.scheduling.schedules.store'), [
            'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'weekday' => 2, 'session_code' => 'B', 'start_time' => '12:00', 'end_time' => '14:00',
            'counter_quota' => 5, 'online_quota' => 5,
        ])->assertSessionHasErrors('domain');   // overlap → DomainException → back()->withErrors

        $this->put($this->url('panel.scheduling.schedules.update', ['schedule' => $schedule->id]), [
            'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'weekday' => 2, 'session_code' => 'A', 'start_time' => '10:00', 'end_time' => '13:00',
            'counter_quota' => 12, 'online_quota' => 8, 'buffer_quota' => 5,
        ])->assertRedirect();
        $this->assertSame('10:00:00', $schedule->fresh()->start_time);

        $tuesday = $this->today()->next(CarbonImmutable::TUESDAY);
        $this->post($this->url('panel.scheduling.overrides.store'), [
            'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'override_date' => $tuesday->toDateString(), 'session_code' => 'A', 'type' => 'late_start', 'delay_minutes' => 30,
        ])->assertRedirect();

        $this->get($this->url('panel.scheduling.sessions.index', ['doctor' => $doctor->id, 'branch' => $branch->id, 'date' => $tuesday->toDateString()]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Scheduling/SessionDay')->has('sessions', 1)->where('sessions.0.delay_minutes', 30)->where('sessions.0.remaining.online', 8));

        $this->get($this->url('panel.scheduling.index', ['doctor' => $doctor->id, 'branch' => $branch->id]))
            ->assertInertia(fn (AssertableInertia $p) => $p->has('schedules', 1)->has('overrides', 1));

        $this->delete($this->url('panel.scheduling.schedules.destroy', ['schedule' => $schedule->id]))->assertRedirect();
        $this->assertFalse($schedule->fresh()->is_active);
    }

    public function test_receptionist_cannot_edit_templates(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $doctor = Doctor::factory()->complete()->create();

        $this->post($this->url('panel.scheduling.schedules.store'), [
            'doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'weekday' => 1, 'session_code' => 'A', 'start_time' => '09:00', 'end_time' => '13:00', 'counter_quota' => 10, 'online_quota' => 10,
        ])->assertForbidden();
    }

    public function test_public_availability_api_materialises_and_lists_online_remaining(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $from = $this->today()->addDays(20);

        $this->getJson($this->url('api.scheduling.availability', ['slug' => $doctor->slug, 'from' => $from->toDateString(), 'to' => $from->addDay()->toDateString()]))
            ->assertOk()
            ->assertJsonPath('doctor.slug', $doctor->slug)
            ->assertJsonCount(2, 'days')
            ->assertJsonPath('days.0.sessions.0.code', 'A')
            ->assertJsonPath('days.0.sessions.0.online_remaining', 10);

        $this->getJson($this->url('api.scheduling.availability', ['slug' => 'nobody']))->assertNotFound();
    }

    public function test_api_serial_routes_require_sanctum_auth(): void
    {
        $session = $this->openSession();
        $this->postJson($this->url('api.sessions.call-next', ['session' => $session->public_id]))->assertUnauthorized();

        $this->actingAsStaff(Role::Receptionist);
        $serial = $this->allocate($session);
        app(CheckInSerial::class)->handle($serial, Actor::system());
        $this->postJson($this->url('api.sessions.call-next', ['session' => $session->public_id]))->assertOk()->assertJsonPath('called.display_code', 'A-001');
        $this->assertSame(1, Serial::query()->where('session_instance_id', $session->id)->where('status', 'in_consultation')->count());
    }
}
