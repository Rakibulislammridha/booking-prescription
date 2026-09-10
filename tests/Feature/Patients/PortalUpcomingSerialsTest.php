<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Patients\Queries\PatientTimelineQuery;
use App\Domain\Patients\Queries\UpcomingSerialsQuery;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\Support\QueueLinks;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use PHPUnit\Framework\Attributes\Group;
use Tests\Feature\Queue\Concerns\QueueFixtures;
use Tests\TestCase;

/**
 * The portal home's "Upcoming serials" card (BRIEF §5.E + §5.H): the household's bookings from today on, the queue
 * link for today's, the hold, the live hint, the empty state, one bounded query, and — above all — nobody else's.
 */
final class PortalUpcomingSerialsTest extends TestCase
{
    use QueueFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        Clock::freeze('2026-09-10 10:00');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    public function test_the_card_lists_todays_and_future_serials_of_the_household_in_order_and_nobody_elses(): void
    {
        [$owner, $child] = $this->household();
        $stranger = Patient::factory()->create(['mobile' => '+8801999999999']);
        $rahman = Doctor::factory()->complete()->create(['slug' => 'dr-rahman', 'name' => 'Dr. Md. Abdur Rahman', 'name_bn' => 'ডা. রহমান']);
        $karim = Doctor::factory()->complete()->create(['slug' => 'dr-karim']);
        $today = Clock::today();

        $mine = $this->book($owner, $rahman, $today, 'B', serial: ['status' => SerialStatus::CheckedIn], appointment: ['status' => AppointmentStatus::CheckedIn]);
        $childs = $this->book($child, $rahman, $today, 'B');
        $strangers = $this->book($stranger, $rahman, $today, 'B');
        $later = $this->book($owner, $karim, $today->addDays(2), 'A');
        $this->book($owner, $karim, $today, 'A', appointment: ['status' => AppointmentStatus::Cancelled, 'cancelled_at' => now()]);
        $this->book($child, $karim, $today->subDay(), 'A');
        $this->book($owner, $karim, $today->addDay(), 'A', appointment: ['status' => AppointmentStatus::Completed]);

        $this->actingAsPatient($owner);

        $response = $this->get('/portal')->assertOk();
        $response->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Portal/Home')
            ->has('upcoming', 3)
            ->where('upcoming.0.appointment_id', $mine->public_id)
            ->where('upcoming.0.status', 'checked_in')
            ->where('upcoming.0.is_today', true)
            ->where('upcoming.0.patient.name', $owner->name)
            ->where('upcoming.0.doctor', ['slug' => 'dr-rahman', 'name' => 'Dr. Md. Abdur Rahman', 'name_bn' => 'ডা. রহমান', 'room' => $rahman->room_label])
            ->where('upcoming.0.branch.name', $this->mainBranch()->name)
            ->where('upcoming.0.session.code', 'B')
            ->where('upcoming.0.session.date', $today->toDateString())
            ->where('upcoming.0.session.planned_start_at', $mine->sessionInstance?->planned_start_at->toIso8601ZuluString())
            ->where('upcoming.0.serial.display_code', 'B-001')
            ->where('upcoming.0.serial.status', 'checked_in')
            ->where('upcoming.0.queue_url', '/q/dr-rahman/today?s='.$mine->serial?->public_id)
            ->where('upcoming.0.hold_expires_at', null)
            ->where('upcoming.0.pay_url', null)
            ->where('upcoming.0.live', null)
            ->where('upcoming.1.appointment_id', $childs->public_id)
            ->where('upcoming.1.patient.name', 'Child')
            ->where('upcoming.1.serial.display_code', 'B-002')
            ->where('upcoming.1.queue_url', '/q/dr-rahman/today?s='.$childs->serial?->public_id)
            ->where('upcoming.2.appointment_id', $later->public_id)
            ->where('upcoming.2.is_today', false)
            ->where('upcoming.2.queue_url', null)
            ->where('upcoming.2.serial.status', 'booked')
        );

        $json = json_encode($response->viewData('page'), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString((string) $strangers->serial?->public_id, $json, "another household's serial leaked into the portal");
        $this->assertStringNotContainsString($strangers->public_id, $json);

        // Acting for the child changes the timeline, never the card: the phone follows everyone it booked for.
        $this->patch('/portal/acting-for', ['patient' => $child->public_id]);
        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p->where('acting_for.public_id', $child->public_id)->has('upcoming', 3));
    }

    public function test_a_serial_held_for_advance_payment_carries_its_expiry_and_the_pay_link(): void
    {
        [$owner] = $this->household();
        $doctor = Doctor::factory()->complete()->create(['slug' => 'dr-rahman']);
        $held = $this->book($owner, $doctor, Clock::today(), 'A', appointment: ['status' => AppointmentStatus::Pending, 'confirmed_at' => null]);

        $this->actingAsPatient($owner);

        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p
            ->has('upcoming', 1)
            ->where('upcoming.0.status', 'pending')
            ->where('upcoming.0.serial.status', 'booked')
            ->where('upcoming.0.hold_expires_at', $held->created_at?->addMinutes(30)->toIso8601ZuluString())
            ->where('upcoming.0.pay_url', '/booking/confirmed/'.$held->public_id)
            ->where('upcoming.0.queue_url', QueueLinks::forSerial('dr-rahman', $held->serial?->public_id))
        );
    }

    public function test_with_nothing_booked_the_card_is_empty(): void
    {
        [$owner] = $this->household();
        $doctor = Doctor::factory()->complete()->create();
        $this->book($owner, $doctor, Clock::today()->subDay(), 'A');
        $this->book($owner, $doctor, Clock::today(), 'A', appointment: ['status' => AppointmentStatus::Completed]);

        $this->actingAsPatient($owner);

        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p->where('upcoming', []));
        $this->assertSame([], app(UpcomingSerialsQuery::class)->fetch([]));
    }

    public function test_the_card_costs_one_statement_and_the_page_does_not_grow_with_the_number_of_serials(): void
    {
        [$owner, $child] = $this->household();
        $doctor = Doctor::factory()->complete()->create(['slug' => 'dr-rahman']);
        $today = Clock::today();
        $this->book($owner, $doctor, $today, 'A');
        $ids = [$owner->id, $child->id];

        $this->assertSame(1, $this->countQueries(fn () => app(UpcomingSerialsQuery::class)->fetch($ids)));

        $this->actingAsPatient($owner);
        $this->get('/portal')->assertOk();                                   // warm the tenant + feature-flag lookups of a first request
        $withOne = $this->countQueries(fn () => $this->get('/portal')->assertOk());

        $this->book($child, $doctor, $today, 'A');
        $this->book($owner, $doctor, $today->addDay(), 'A');
        $this->book($child, $doctor, $today->addDay(), 'A');
        $this->book($owner, $doctor, $today->addDays(3), 'B');
        $this->book($child, $doctor, $today->addDays(5), 'A');

        $withSix = $this->countQueries(fn () => $this->get('/portal')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('upcoming', 6)));

        $this->assertSame($withOne, $withSix, 'the portal home runs a query per serial');
    }

    #[Group('realtime')]
    public function test_the_live_hint_reads_the_existing_queue_state_without_rebuilding_it(): void
    {
        [$owner] = $this->household();
        $doctor = $this->queueDoctor('dr-rahman');
        $session = $this->queueSession($doctor);
        $first = $this->issue($session, Patient::factory()->create()->id);
        $mine = $this->issue($session, $owner->id);
        $appointment = Appointment::factory()->create(['patient_id' => $owner->id, 'session_instance_id' => $session->id, 'doctor_id' => $doctor->id, 'branch_id' => $session->branch_id, 'serial_id' => $mine->id]);
        $mine->forceFill(['appointment_id' => $appointment->id])->save();
        $this->checkIn($first);
        $this->checkIn($mine);
        $called = $this->callNext($session);
        $this->assertSame($first->id, $called?->id);

        $repository = app(QueueStateRepository::class);
        $repository->forget($session->fresh() ?? $session);
        $this->actingAsPatient($owner);

        // Cold cache: no hint, and — crucially — no rebuild (the poll endpoint and the page own that).
        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p->where('upcoming.0.live', null));
        $this->assertNull($repository->snapshotByPublicId($session->public_id));

        $repository->rebuild($session->fresh() ?? $session);

        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('upcoming.0.serial.display_code', $mine->display_code)
            ->where('upcoming.0.serial.status', 'checked_in')
            ->where('upcoming.0.live', ['now_serving' => $first->display_code, 'ahead' => 0])
        );

        $repository->forget($session->fresh() ?? $session);
    }

    public function test_a_patient_never_sees_another_tenants_serials_for_the_same_mobile(): void
    {
        $this->asTenant('b');
        $twin = Patient::factory()->create(['mobile' => '+8801712345678']);
        $this->book($twin, Doctor::factory()->complete()->create(['slug' => 'dr-rahman']), Clock::today(), 'A');

        $this->asTenant('a');
        [$owner] = $this->household();
        $this->actingAsPatient($owner);

        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p->where('patient.public_id', $owner->public_id)->where('upcoming', []));

        $this->assertTenantIsolated('appointments', function (): void {
            $this->book(Patient::factory()->create(), Doctor::factory()->complete()->create(), Clock::today(), 'A');
        });
    }

    public function test_a_visit_row_links_to_the_live_queue_today_and_to_the_prescription_once_issued(): void
    {
        [$owner] = $this->household();
        $doctor = Doctor::factory()->complete()->create(['slug' => 'dr-rahman']);
        $todays = $this->book($owner, $doctor, Clock::today(), 'B', serial: ['status' => SerialStatus::CheckedIn], appointment: ['status' => AppointmentStatus::CheckedIn]);
        $serial = $todays->serial;
        $this->assertNotNull($serial);
        $open = Visit::factory()->fromSerial($serial)->create(['started_at' => now()]);
        $stale = Visit::factory()->create(['patient_id' => $owner->id, 'doctor_id' => $doctor->id, 'started_at' => now()->subDays(3)]);

        $entries = collect(app(PatientTimelineQuery::class)->fetch($owner, null, 10, ['visit'])->toArray()['data'])->keyBy('id');

        $this->assertSame(QueueLinks::forSerial('dr-rahman', $serial->public_id), $entries[$open->id]['meta']['queue_url']);
        $this->assertNull($entries[$open->id]['meta']['rx_url']);
        $this->assertSame('Dr. '.$doctor->name.' · '.$serial->display_code, 'Dr. '.$entries[$open->id]['subtitle']);
        $this->assertNull($entries[$stale->id]['meta']['queue_url']);

        Prescription::factory()->create(['visit_id' => $open->id, 'status' => PrescriptionStatus::Issued, 'issued_at' => now(), 'verification_code' => 'RXAB12CD', 'snapshot' => ['version' => 1]]);
        Prescription::factory()->create(['visit_id' => $stale->id, 'status' => PrescriptionStatus::Draft, 'verification_code' => null]);

        $entries = collect(app(PatientTimelineQuery::class)->fetch($owner, null, 10, ['visit'])->toArray()['data'])->keyBy('id');
        $this->assertSame('/rx/RXAB12CD', $entries[$open->id]['meta']['rx_url']);
        $this->assertNull($entries[$stale->id]['meta']['rx_url']);

        $this->actingAsPatient($owner);
        $this->get('/portal')->assertInertia(fn (AssertableInertia $p) => $p
            ->where('timeline.data.0.kind', 'visit')
            ->where('timeline.data.0.meta.rx_url', '/rx/RXAB12CD')
            ->where('timeline.data.0.meta.queue_url', QueueLinks::forSerial('dr-rahman', $serial->public_id))
        );
    }

    /** @return array{0: Patient, 1: Patient} */
    private function household(): array
    {
        $owner = Patient::factory()->create(['mobile' => '+8801712345678', 'name' => 'Rahima']);
        $child = Patient::factory()->dependentOf($owner)->create(['name' => 'Child']);

        return [$owner, $child];
    }

    /**
     * A booking the way the engine leaves one: a serial in the session's counter pool and the appointment that owns
     * it, both pointing at each other (SCHEMA §3.3).
     *
     * @param  array<string, mixed>  $serial
     * @param  array<string, mixed>  $appointment
     */
    private function book(Patient $patient, Doctor $doctor, CarbonImmutable $date, string $code, array $serial = [], array $appointment = []): Appointment
    {
        $branch = Branch::query()->where('is_main', true)->firstOrFail();
        $session = SessionInstance::query()->forDay($branch->id, $doctor->id, $date)->where('session_code', $code)->first()
            ?? SessionInstance::factory()->on($date, $code)->create(['doctor_id' => $doctor->id, 'branch_id' => $branch->id]);

        $serialRow = Serial::factory()->create(['session_instance_id' => $session->id, 'patient_id' => $patient->id, ...$serial]);
        $row = Appointment::factory()->create([
            'patient_id' => $patient->id, 'session_instance_id' => $session->id, 'doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'serial_id' => $serialRow->id,
            ...$appointment,
        ]);
        $serialRow->forceFill(['appointment_id' => $row->id])->save();

        return $row->load(['serial', 'sessionInstance']);
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
