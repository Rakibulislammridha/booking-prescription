<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Vital;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** PRESCRIPTION.md §9.1 WriterPageTest: props shape, draft auto-created, query budget, tenant isolation. */
final class WriterPageTest extends TestCase
{
    use PrescriptionTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_show_renders_every_prop_group_and_creates_the_draft_inside_the_request(): void
    {
        [$user, $doctor, $visit] = $this->doctorWithOpenVisit();
        Vital::factory()->for($visit)->create(['weight_kg' => 58, 'height_cm' => 160]);
        $this->assertNull(Prescription::query()->where('visit_id', $visit->id)->first());

        $this->get('/panel/visits/'.$visit->public_id.'/prescribe')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Prescription/Writer')
            ->where('visit.id', $visit->public_id)
            ->where('visit.type', 'opd')
            ->has('visit.chief_complaints', 2)
            ->where('visit.diagnoses.0.icd10_code', 'J06.9')
            ->where('patient.public_id', $visit->patient->public_id)
            ->has('patient.allergies')->has('patient.flags.pregnant')
            ->where('vitals.weight_kg', 58)->where('vitals.bmi', 22.7)
            ->has('recent_visits', 0)
            ->where('prescription.status', 'draft')->where('prescription.version', 1)->has('prescription.items', 0)
            ->where('doctor.id', $doctor->id)->has('doctor.pad.default_language')->where('doctor.prefs.cont_days', 30)
            ->has('quick_pick.top_drugs')->has('quick_pick.templates')->has('quick_pick.snippets')->has('quick_pick.investigations')->has('quick_pick.external_centres')
            ->where('features.ai', false)->has('features.drawing_backgrounds', 8)
            ->has('cheat_sheet_version'));

        $draft = Prescription::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertTrue($draft->isDraft());
        $this->assertSame($draft->id, $draft->root_prescription_id);
        $this->assertSame($draft->id, $visit->fresh()->current_prescription_id);
        $this->assertAudited(AuditAction::View, $visit);
        $this->assertAudited(AuditAction::View, $draft, ['event' => 'viewed']);
        $this->assertAudited(AuditAction::Create, $draft, ['event' => 'created']);
    }

    public function test_second_open_reuses_the_draft_and_stays_within_the_query_budget(): void
    {
        [, , $visit] = $this->doctorWithOpenVisit();
        $this->get('/panel/visits/'.$visit->public_id.'/prescribe')->assertOk();

        DB::connection('pgsql')->enableQueryLog();
        $this->get('/panel/visits/'.$visit->public_id.'/prescribe?format=json')->assertOk()->assertJsonPath('prescription.version', 1);
        $count = count(DB::connection('pgsql')->getQueryLog());
        DB::connection('pgsql')->disableQueryLog();

        $this->assertSame(1, Prescription::query()->where('visit_id', $visit->id)->count());
        $this->assertLessThan(45, $count, "writer open ran {$count} queries");
    }

    public function test_other_tenants_visit_is_404_and_receptionist_is_403(): void
    {
        [, , $visit] = $this->doctorWithOpenVisit();
        $public = $visit->public_id;

        $this->asTenant('b');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->get('/panel/visits/'.$public.'/prescribe')->assertNotFound();

        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);
        $this->get('/panel/visits/'.$public.'/prescribe')->assertForbidden();
    }

    public function test_another_doctor_cannot_open_the_visit_without_view_any(): void
    {
        [, , $visit] = $this->doctorWithOpenVisit();
        $this->actingAsDoctor();
        $this->get('/panel/visits/'.$visit->public_id.'/prescribe')->assertForbidden();
        $this->assertSame(0, Prescription::query()->where('visit_id', $visit->id)->count());
    }
}
