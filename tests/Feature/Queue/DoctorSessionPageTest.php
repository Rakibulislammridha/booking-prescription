<?php

declare(strict_types=1);

namespace Tests\Feature\Queue;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Actions\CreateDraftPrescription;
use App\Domain\Prescription\Actions\IssuePrescription;
use App\Domain\Prescription\Data\IssueRequest;
use App\Domain\Serials\Actions\CallSerial;
use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Serial;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/**
 * "Today's session" — the doctor's own session page and the sidebar entry that opens it (BRIEF §5.E / §5.G):
 * the nav badge's shared prop, the session roster (every patient in serial-number order with sex, age, vitals and
 * the action their state allows), the per-row call, and the post-issue "call next patient" endpoint.
 */
#[Group('realtime')]
final class DoctorSessionPageTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    // ---- the sidebar entry -------------------------------------------------------------------------------------

    public function test_the_sidebar_carries_todays_session_with_the_checked_in_count_for_a_doctor(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);

        [$a, $b] = $this->issueMany($session, 3, withPatients: true);
        $this->checkIn($a);
        $this->checkIn($b);

        $this->get('/panel')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('today_session.session_id', $session->public_id)
                ->where('today_session.code', $session->session_code)
                ->where('today_session.status', 'scheduled')
                ->where('today_session.waiting', 2)          // checked in and waiting for the doctor; the third is still booked
            );
    }

    public function test_nobody_but_a_doctor_gets_the_todays_session_prop(): void
    {
        $this->queueSession($this->queueDoctor());

        foreach ([Role::Receptionist, Role::HospitalAdmin, Role::Accountant] as $role) {
            $this->actingAsStaff($role, $this->mainBranch());
            $this->get('/panel')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('today_session', null));
        }
    }

    public function test_the_badge_costs_exactly_one_query_against_session_instances(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $this->checkIn($this->issue($this->queueSession($doctor), Patient::factory()->create()->id));

        DB::connection('pgsql')->enableQueryLog();
        $this->get('/panel')->assertOk();
        $log = DB::connection('pgsql')->getQueryLog();
        DB::connection('pgsql')->disableQueryLog();

        // The badge reads its own five columns; nothing else on the panel shell may go looking for today's sessions.
        $badge = array_values(array_filter($log, fn (array $q): bool => str_contains((string) $q['query'], '"checked_in_count" from "session_instances"')));
        $this->assertCount(1, $badge, 'the "Today\'s session" badge must be one cheap query per request');
    }

    public function test_a_doctor_with_no_session_today_gets_no_badge(): void
    {
        $this->actingAsDoctor();

        $this->get('/panel')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('today_session', null));
    }

    // ---- the session page --------------------------------------------------------------------------------------

    public function test_the_session_page_lists_every_patient_in_serial_number_order_with_sex_age_vitals_and_status(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);

        // Half a year of slack either side of the birthday, so the age the roster prints is unambiguous.
        $rahima = Patient::factory()->create(['name' => 'Rahima Begum', 'gender' => 'female', 'dob' => now()->subYears(41)->subMonths(6)->toDateString()]);
        $karim = Patient::factory()->create(['name' => 'Karim Mia', 'gender' => 'male', 'dob' => now()->subYears(30)->subMonths(6)->toDateString()]);
        $absent = Patient::factory()->create(['name' => 'Jamal Uddin', 'gender' => 'male']);

        $first = $this->issue($session, $rahima->id);
        $second = $this->issue($session, $karim->id);
        $third = $this->issue($session, $absent->id);

        // The compounder recorded the first patient's vitals at the desk; the others have none yet.
        $visit = Visit::factory()->fromSerial($first)->create();
        Vital::factory()->for($visit)->create(['bp_systolic' => 120, 'bp_diastolic' => 80, 'pulse_bpm' => 88, 'temperature_c' => 38.4, 'spo2_percent' => 97, 'weight_kg' => 60.5]);

        $this->checkIn($first);
        $this->checkIn($second);

        $this->get('/panel/queue/doctor')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page) use ($first, $second, $third): void {
                $page->component('Queue/Doctor')
                    ->where('roster.'.$first->public_id.'.patient.name', 'Rahima Begum')
                    ->where('roster.'.$first->public_id.'.patient.sex', 'female')
                    ->where('roster.'.$first->public_id.'.patient.age_text', '41y')
                    ->where('roster.'.$first->public_id.'.status', 'checked_in')
                    ->where('roster.'.$first->public_id.'.vitals.bp_systolic', 120)
                    ->where('roster.'.$first->public_id.'.vitals.pulse_bpm', 88)
                    // Temperature travels in °C, the clinical canonical unit; every screen renders °F.
                    ->where('roster.'.$first->public_id.'.vitals.temperature_c', 38.4)
                    ->where('roster.'.$first->public_id.'.vitals.spo2_percent', 97)
                    ->where('roster.'.$first->public_id.'.vitals.weight_kg', 60.5)
                    ->where('roster.'.$second->public_id.'.patient.sex', 'male')
                    ->where('roster.'.$second->public_id.'.vitals', null)       // nothing recorded → null, never a zero
                    ->where('roster.'.$third->public_id.'.status', 'booked')
                    ->where('roster.'.$third->public_id.'.prescription', null);

                /** @var array<string, mixed> $roster */
                $roster = $page->toArray()['props']['roster'];
                $this->assertSame([$first->public_id, $second->public_id, $third->public_id], array_keys($roster), 'the roster is in serial-number order, like the desk board');
            });
    }

    public function test_the_session_page_carries_the_counts_and_the_row_of_the_patient_in_the_chamber(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);

        [$a, $b] = $this->issueMany($session, 3, withPatients: true);
        $this->checkIn($a);
        $this->checkIn($b);
        $called = $this->callNext($session->fresh());
        $this->assertNotNull($called);

        $this->get('/panel/queue/doctor')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Queue/Doctor')
                ->where('state.counts.booked', 1)
                ->where('state.counts.checked_in', 1)
                ->where('state.counts.in_consultation', 1)
                ->where('state.counts.completed', 0)
                ->where('state.counts.waiting', 2)
                ->where('roster.'.$called->public_id.'.status', 'in_consultation')
                ->where('roster.'.$called->public_id.'.visit_id', Visit::query()->where('serial_id', $called->id)->value('public_id'))
                ->has('sessions.0.planned_end_at')
            );
    }

    public function test_an_issued_prescription_puts_view_and_print_on_the_row(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);
        $serial = $this->issue($session, Patient::factory()->create()->id);
        $this->checkIn($serial);
        $called = $this->callNext($session->fresh());
        $this->assertNotNull($called);

        $rx = $this->issueFor($called);

        $this->get('/panel/queue/doctor')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('roster.'.$called->public_id.'.prescription.id', $rx->public_id)
                ->where('roster.'.$called->public_id.'.prescription.status', 'issued')
                ->where('roster.'.$called->public_id.'.status', 'completed')   // issuing completed the consultation
            );
    }

    public function test_the_session_page_stays_within_its_query_budget_however_many_patients(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor, counter: 30, online: 10);

        foreach ($this->issueMany($session, 12, withPatients: true) as $serial) {
            Visit::factory()->fromSerial($serial)->create();
            $this->checkIn($serial);
        }

        $this->get('/panel/queue/doctor')->assertOk();          // warm: the queue state snapshot lands in Redis

        DB::connection('pgsql')->enableQueryLog();
        $this->get('/panel/queue/doctor')->assertOk();
        $count = count(DB::connection('pgsql')->getQueryLog());
        DB::connection('pgsql')->disableQueryLog();

        $this->assertLessThan(35, $count, "the session page ran {$count} queries for 12 patients — the roster must not be per-row");
    }

    public function test_the_roster_endpoint_returns_the_same_rows_for_the_pages_live_refresh(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);
        $serial = $this->issue($session, Patient::factory()->create(['name' => 'Rahima Begum'])->id);
        $this->checkIn($serial);

        $this->getJson('/panel/queue/doctor/roster')
            ->assertOk()
            ->assertJsonPath('session_id', $session->public_id)
            ->assertJsonPath('version', $session->fresh()?->version)
            ->assertJsonPath('roster.'.$serial->public_id.'.patient.name', 'Rahima Begum')
            ->assertJsonPath('roster.'.$serial->public_id.'.status', 'checked_in');
    }

    // ---- calling one specific patient from the list -------------------------------------------------------------

    public function test_calling_a_specific_patient_is_the_doctors_own_session_or_an_operator(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $mine = $this->queueSession($doctor);
        $ownSerial = $this->checkIn($this->issue($mine, Patient::factory()->create()->id));

        $otherDoctor = $this->queueDoctor('dr-other');
        $otherSession = $this->queueSession($otherDoctor, 'C');
        $otherSerial = $this->checkIn($this->issue($otherSession, Patient::factory()->create()->id));

        // the doctor calls a patient of their own session…
        $this->postJson('/panel/serials/'.$ownSerial->public_id.'/call')->assertOk()->assertJsonPath('serial.status', 'in_consultation');
        // …and never one of a colleague's chamber, permission or no permission
        $this->postJson('/panel/serials/'.$otherSerial->public_id.'/call')->assertForbidden();
        $this->assertSame(SerialStatus::CheckedIn, $otherSerial->fresh()?->status);

        // an operator with queue.call-next drives any doctor's queue (the desk's call-next button, BRIEF §5.F)
        $this->actingAsStaff(Role::Receptionist, $this->mainBranch());
        $this->postJson('/panel/serials/'.$otherSerial->public_id.'/call')->assertOk()->assertJsonPath('serial.status', 'in_consultation');

        // an accountant holds no queue permission at all
        $this->actingAsStaff(Role::Accountant, $this->mainBranch());
        $this->postJson('/panel/serials/'.$this->checkIn($this->issue($mine, Patient::factory()->create()->id))->public_id.'/call')->assertForbidden();
    }

    // ---- issue → call next -------------------------------------------------------------------------------------

    public function test_issuing_completes_the_consultation_and_call_next_lands_on_the_next_patients_writer(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);

        [$a, $b] = $this->issueMany($session, 2, withPatients: true);
        $this->checkIn($a);
        $this->checkIn($b);

        $called = $this->callNext($session->fresh());
        $this->assertNotNull($called);
        $this->assertSame($a->id, $called->id);

        // Issuing is what completes the consultation (CompleteConsultationOnPrescriptionIssued) …
        $this->issueFor($called);
        $this->assertSame(SerialStatus::Completed, $called->fresh()?->status);
        $this->assertNull($session->fresh()?->now_serving_serial_id);

        // … so the post-issue bar's one click calls the next patient AND opens their visit.
        $response = $this->postJson('/panel/queue/sessions/'.$session->public_id.'/call-next-visit')->assertOk();

        $response->assertJsonPath('called.public_id', $b->public_id)
            ->assertJsonPath('called.status', 'in_consultation')
            ->assertJsonPath('waiting_booked', 0);

        $visitId = Visit::query()->where('serial_id', $b->id)->value('public_id');
        $this->assertNotNull($visitId);
        $this->assertSame('/panel/visits/'.$visitId.'/prescribe', parse_url((string) $response->json('writer_url'), PHP_URL_PATH));
        $this->assertSame($visitId, $response->json('visit.id'));

        // one completion, not two: the serial the doctor just finished is completed exactly once
        $this->assertSame(1, Serial::query()->where('session_instance_id', $session->id)->where('status', SerialStatus::Completed->value)->count());
        $this->get((string) $response->json('writer_url'))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Prescription/Writer'));
    }

    public function test_call_next_refuses_while_the_previous_patient_is_still_in_consultation(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);

        [$a, $b] = $this->issueMany($session, 2, withPatients: true);
        $this->checkIn($a);
        $this->checkIn($b);
        $called = $this->callNext($session->fresh());
        $this->assertNotNull($called);

        // The engine would happily pre-call the next serial for a desk; the doctor's own flow must not, or two
        // patients are in the chamber. The refusal is surfaced with its code, never swallowed.
        $this->postJson('/panel/queue/sessions/'.$session->public_id.'/call-next-visit')
            ->assertStatus(409)
            ->assertJsonPath('code', 'queue.chamber_occupied');

        $this->assertSame(SerialStatus::CheckedIn, $b->fresh()?->status, 'nobody else was called');
        $this->assertSame($called->id, $session->fresh()?->now_serving_serial_id);
    }

    public function test_call_next_refuses_for_a_serial_left_in_consultation_that_is_no_longer_now_serving(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);

        [$a, $b, $c] = $this->issueMany($session, 3, withPatients: true);
        foreach ([$a, $b, $c] as $serial) {
            $this->checkIn($serial);
        }

        // A called on top of B at the desk: now_serving moves to B, but A is still in consultation.
        $this->callNext($session->fresh());
        app(CallSerial::class)->handle($b->fresh() ?? $b, Actor::user($user->id));
        app(CompleteConsultation::class)->handle($b->fresh() ?? $b, Actor::user($user->id));

        $this->assertNull($session->fresh()?->now_serving_serial_id);
        $this->assertSame(SerialStatus::InConsultation, $a->fresh()?->status);

        $this->postJson('/panel/queue/sessions/'.$session->public_id.'/call-next-visit')
            ->assertStatus(409)
            ->assertJsonPath('code', 'queue.chamber_occupied');

        $this->assertSame(SerialStatus::CheckedIn, $c->fresh()?->status, 'nobody was called on top of the patient still in the chamber');
    }

    public function test_call_next_says_no_one_is_waiting_instead_of_failing(): void
    {
        $user = $this->actingAsDoctor();
        $doctor = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $session = $this->queueSession($doctor);
        $this->issueMany($session, 2, withPatients: true);      // booked, nobody checked in

        $this->postJson('/panel/queue/sessions/'.$session->public_id.'/call-next-visit')
            ->assertOk()
            ->assertJsonPath('called', null)
            ->assertJsonPath('visit', null)
            ->assertJsonPath('writer_url', null)
            ->assertJsonPath('waiting_booked', 2);
    }

    public function test_call_next_is_refused_to_a_doctor_on_a_colleagues_session_and_to_an_accountant(): void
    {
        $other = $this->queueDoctor('dr-other');
        $otherSession = $this->queueSession($other);
        $this->checkIn($this->issue($otherSession, Patient::factory()->create()->id));

        $this->actingAsDoctor();
        $this->postJson('/panel/queue/sessions/'.$otherSession->public_id.'/call-next-visit')->assertForbidden();

        $this->actingAsStaff(Role::Accountant, $this->mainBranch());
        $this->postJson('/panel/queue/sessions/'.$otherSession->public_id.'/call-next-visit')->assertForbidden();

        // the desk may: it is the same permission the board's call-next button holds
        $this->actingAsStaff(Role::Receptionist, $this->mainBranch());
        $this->postJson('/panel/queue/sessions/'.$otherSession->public_id.'/call-next-visit')->assertOk()->assertJsonPath('called.status', 'in_consultation');
    }

    public function test_an_operator_calling_next_gets_the_visit_but_no_writer_url(): void
    {
        $doctor = $this->queueDoctor('dr-other');
        $session = $this->queueSession($doctor);
        $serial = $this->checkIn($this->issue($session, Patient::factory()->create()->id));

        $this->actingAsStaff(Role::Receptionist, $this->mainBranch());

        $this->postJson('/panel/queue/sessions/'.$session->public_id.'/call-next-visit')
            ->assertOk()
            ->assertJsonPath('called.public_id', $serial->public_id)
            ->assertJsonPath('writer_url', null)               // a receptionist is not a prescriber (VisitPolicy::write)
            ->assertJsonPath('visit.serial_display', $serial->display_code);
    }

    /** The issued prescription of a serial's visit, through the real actions (an empty Rx is enough to freeze one). */
    private function issueFor(Serial $serial): Prescription
    {
        $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();
        $doctor = Doctor::query()->whereKey($visit->doctor_id)->firstOrFail();
        $draft = app(CreateDraftPrescription::class)->handle($visit, $doctor, Actor::user((int) auth('web')->id()));

        return app(IssuePrescription::class)->handle($draft, new IssueRequest, Actor::user((int) auth('web')->id()));
    }
}
