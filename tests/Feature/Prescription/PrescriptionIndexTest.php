<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Visit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * GET /panel/prescriptions (`panel.prescriptions.index`): the page and its props, every filter, server-side
 * pagination, the role matrix (an accountant reads no clinical record; a doctor sees their own patients' prescriptions
 * and nobody else's unless `prescriptions.view.any`), a BOUNDED query count and tenant isolation.
 */
final class PrescriptionIndexTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_index_lists_prescriptions_newest_first_with_the_filter_options(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $doctor = Doctor::factory()->complete()->create(['name' => 'Dr. Rahman']);
        $patient = Patient::factory()->create(['name' => 'Rehana Begum']);
        $older = $this->prescription($patient, $doctor, issuedAt: '2026-08-01 10:00');
        $newer = $this->prescription($patient, $doctor, issuedAt: '2026-09-05 10:00');
        $draft = $this->prescription($patient, $doctor, PrescriptionStatus::Draft);

        $this->get('/panel/prescriptions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Prescription/Index')
                ->where('filters', ['q' => '', 'doctor' => null, 'status' => null, 'from' => null, 'to' => null])
                ->has('prescriptions.data', 3)
                ->where('prescriptions.data.0.id', $draft->public_id)   // a draft's moment is its creation: now
                ->where('prescriptions.data.1.id', $newer->public_id)
                ->where('prescriptions.data.2.id', $older->public_id)
                ->where('prescriptions.data.1.status', 'issued')
                ->where('prescriptions.data.1.version', 1)
                ->where('prescriptions.data.1.pdf_status', 'pending')
                ->where('prescriptions.data.1.items_count', 0)
                ->where('prescriptions.data.1.diagnosis', 'Acute upper respiratory infection, unspecified')
                ->where('prescriptions.data.1.patient.name', 'Rehana Begum')
                ->where('prescriptions.data.1.patient.patient_code', $patient->patient_code)
                ->where('prescriptions.data.1.doctor.public_id', $doctor->public_id)
                ->where('prescriptions.data.1.visit_id', $newer->visit->public_id)
                ->missing('prescriptions.data.1.snapshot')
                ->where('prescriptions.meta.per_page', 50)
                ->where('prescriptions.meta.total', 3)
                ->where('options.statuses', PrescriptionStatus::values())
                // Contains, not "is first": the filter offers every active doctor, and the tenant may hold others.
                ->where('options.doctors', fn (Collection $doctors) => $doctors->contains('public_id', $doctor->public_id)));
    }

    public function test_the_list_is_searched_by_patient_name_mobile_and_code(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $doctor = Doctor::factory()->complete()->create();
        $rahima = Patient::factory()->create(['name' => 'Rahima Begum', 'mobile' => '+8801711111111']);
        $karim = Patient::factory()->create(['name' => 'Karim Mia', 'mobile' => '+8801722222222']);
        $this->prescription($rahima, $doctor);
        $this->prescription($karim, $doctor);

        $this->get('/panel/prescriptions?q=rahima')->assertInertia(fn (AssertableInertia $p) => $p->where('filters.q', 'rahima')->has('prescriptions.data', 1)->where('prescriptions.data.0.patient.name', 'Rahima Begum'));
        $this->get('/panel/prescriptions?q=01722222222')->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 1)->where('prescriptions.data.0.patient.name', 'Karim Mia'));
        $this->get('/panel/prescriptions?q='.$karim->patient_code)->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 1)->where('prescriptions.data.0.patient.name', 'Karim Mia'));
        $this->get('/panel/prescriptions?q=nobody')->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 0)->where('prescriptions.meta.total', 0));
    }

    public function test_the_list_is_filtered_by_doctor_status_and_dhaka_day_range(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $rahman = Doctor::factory()->complete()->create(['name' => 'Dr. Rahman']);
        $sultana = Doctor::factory()->complete()->create(['name' => 'Dr. Sultana']);
        $patient = Patient::factory()->create();
        // 00:30 on 1 August in Dhaka is still 31 July in UTC: a UTC-based range would lose this row.
        $august = $this->prescription($patient, $rahman, issuedAt: '2026-08-01 00:30');
        $september = $this->prescription($patient, $sultana, issuedAt: '2026-09-05 10:00');
        $voided = $this->prescription($patient, $sultana, PrescriptionStatus::Voided, '2026-09-06 10:00');
        $this->prescription($patient, $rahman, PrescriptionStatus::Draft);

        $this->get('/panel/prescriptions?doctor='.$sultana->public_id)
            ->assertInertia(fn (AssertableInertia $p) => $p->where('filters.doctor', $sultana->public_id)->has('prescriptions.data', 2)->where('prescriptions.data.0.id', $voided->public_id)->where('prescriptions.data.1.id', $september->public_id));
        $this->get('/panel/prescriptions?status=voided')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('filters.status', 'voided')->has('prescriptions.data', 1)->where('prescriptions.data.0.id', $voided->public_id));
        $this->get('/panel/prescriptions?status=draft')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 1)->where('prescriptions.data.0.status', 'draft'));
        $this->get('/panel/prescriptions?from=2026-08-01&to=2026-08-31')
            ->assertInertia(fn (AssertableInertia $p) => $p->where('filters.from', '2026-08-01')->where('filters.to', '2026-08-31')->has('prescriptions.data', 1)->where('prescriptions.data.0.id', $august->public_id));
        $this->get('/panel/prescriptions?from=2026-09-06')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 2)->where('prescriptions.data.1.id', $voided->public_id));
        $this->get('/panel/prescriptions?doctor='.$rahman->public_id.'&status=issued&to=2026-08-31')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 1)->where('prescriptions.data.0.id', $august->public_id));
    }

    public function test_the_list_is_paginated_on_the_server_fifty_a_page(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $doctor = Doctor::factory()->complete()->create();
        $patient = Patient::factory()->create();

        foreach (Visit::factory()->count(55)->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]) as $visit) {
            Prescription::factory()->create(['visit_id' => $visit->id]);
        }

        $this->get('/panel/prescriptions?status=draft')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 50)->where('prescriptions.meta.current_page', 1)->where('prescriptions.meta.last_page', 2)->where('prescriptions.meta.total', 55));
        $this->get('/panel/prescriptions?status=draft&page=2')
            ->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 5)->where('prescriptions.meta.current_page', 2)->where('filters.status', 'draft'));
    }

    public function test_the_role_matrix(): void
    {
        $doctor = Doctor::factory()->complete()->create();
        $this->prescription(Patient::factory()->create(), $doctor);

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->get('/panel/prescriptions')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 1));

        $this->actingAsStaff(Role::Receptionist);
        $this->get('/panel/prescriptions')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 1));

        $this->actingAsStaff(Role::Accountant);
        $this->get('/panel/prescriptions')->assertForbidden();

        $this->actingAsDoctor();
        $this->get('/panel/prescriptions')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 0));

        $this->app['auth']->forgetGuards();
        $this->get('/panel/prescriptions')->assertRedirect();
    }

    public function test_a_doctor_sees_the_prescriptions_of_the_patients_they_have_treated_and_nobody_elses(): void
    {
        $user = $this->actingAsDoctor();
        $me = $user->doctor()->firstOrFail();
        $colleague = Doctor::factory()->complete()->create(['name' => 'Dr. Colleague']);
        $mine = Patient::factory()->create(['name' => 'Mine']);
        $theirs = Patient::factory()->create(['name' => 'Theirs']);
        $shared = Patient::factory()->create(['name' => 'Shared']);

        $ownRx = $this->prescription($mine, $me);
        $this->prescription($theirs, $colleague);
        $sharedRx = $this->prescription($shared, $colleague, issuedAt: '2026-09-01 10:00');
        Visit::factory()->create(['patient_id' => $shared->id, 'doctor_id' => $me->id]);

        // Two rows: my own, and a colleague's for a patient I have also treated. Never the patient I have not seen.
        $this->get('/panel/prescriptions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->has('prescriptions.data', 2)
                ->where('prescriptions.data.0.id', $ownRx->public_id)
                ->where('prescriptions.data.1.id', $sharedRx->public_id)
                ->where('prescriptions.meta.total', 2));

        // Searching for the patient I have not treated finds nothing — the constraint is applied before the search.
        $this->get('/panel/prescriptions?q=theirs')->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 0));

        // The wider permission lifts the constraint.
        $user->givePermissionTo(Permission::PrescriptionsViewAny->value);
        $this->get('/panel/prescriptions')->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 3));
    }

    public function test_a_malformed_filter_is_a_validation_error_not_a_crash(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->from('/panel')->get('/panel/prescriptions?status=bogus')->assertRedirect('/panel')->assertSessionHasErrors('status');
        $this->from('/panel')->get('/panel/prescriptions?from=yesterday')->assertRedirect('/panel')->assertSessionHasErrors('from');
        $this->get('/panel/prescriptions?doctor='.str_repeat('0', 26))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 0));
    }

    public function test_the_page_costs_a_bounded_number_of_queries_however_many_rows_and_doctors_it_lists(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->seedRows(5);

        $count = fn (): int => $this->countQueries(fn () => $this->get('/panel/prescriptions')->assertOk());

        // Warm the request-level caches (permissions, settings) so the comparison is about the LIST's own queries.
        $count();
        $before = $count();

        $this->seedRows(10);
        $after = $count();

        $this->assertSame($before, $after, "the prescriptions index went from {$before} to {$after} queries after adding ten rows with their own patients and doctors");
        $this->assertLessThanOrEqual(20, $after, 'a list page must not need dozens of round trips');
    }

    public function test_a_restricted_doctor_pays_the_same_bounded_count(): void
    {
        $user = $this->actingAsDoctor();
        $me = $user->doctor()->firstOrFail();

        for ($i = 0; $i < 5; $i++) {
            $this->prescription(Patient::factory()->create(), $me);
        }

        $count = fn (): int => $this->countQueries(fn () => $this->get('/panel/prescriptions')->assertOk());
        $count();
        $before = $count();

        for ($i = 0; $i < 10; $i++) {
            $this->prescription(Patient::factory()->create(), $me);
        }

        $this->assertSame($before, $count());
        $this->assertLessThanOrEqual(20, $before);
    }

    public function test_tenant_isolation(): void
    {
        $this->assertTenantIsolated('prescriptions', function (): void {
            $this->prescription(Patient::factory()->create(), Doctor::factory()->complete()->create());
        });

        $this->asTenant('a');
        $public = Prescription::query()->orderByDesc('id')->firstOrFail()->public_id;

        $this->asTenant('b');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->get('/panel/prescriptions')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->has('prescriptions.data', 0)->where('prescriptions.meta.total', 0));
        $this->get('/panel/prescriptions/'.$public)->assertNotFound();
    }

    /**
     * A prescription for $patient by $doctor. Non-draft rows are fabricated the way ReportFixture does — a draft
     * moved to issued with the columns the check constraint demands — since the list never reads the snapshot.
     */
    private function prescription(Patient $patient, Doctor $doctor, PrescriptionStatus $status = PrescriptionStatus::Issued, ?string $issuedAt = null): Prescription
    {
        $visit = Visit::factory()->urti()->create(['patient_id' => $patient->id, 'doctor_id' => $doctor->id]);
        $rx = Prescription::factory()->create(['visit_id' => $visit->id]);

        if ($status !== PrescriptionStatus::Draft) {
            $rx->forceFill([
                'status' => PrescriptionStatus::Issued,
                'issued_at' => CarbonImmutable::parse($issuedAt ?? 'now', 'Asia/Dhaka')->utc(),
                'snapshot' => ['schema' => 1],
                'snapshot_sha256' => hash('sha256', $rx->public_id),
                'verification_code' => strtoupper(substr(md5($rx->public_id), 0, 12)),
            ])->save();
        }

        if ($status === PrescriptionStatus::Voided) {
            $rx->forceFill(['status' => $status, 'voided_at' => now(), 'void_reason' => 'test'])->save();
        } elseif ($status === PrescriptionStatus::Amended) {
            $rx->forceFill(['status' => $status])->save();
        }

        return $rx->refresh();
    }

    private function seedRows(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $this->prescription(Patient::factory()->create(), Doctor::factory()->complete()->create());
        }
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
