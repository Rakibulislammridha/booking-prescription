<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\PatientMedication;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class PanelPagesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_index_lists_recent_patients_and_filters_by_query(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        Patient::factory()->create(['name' => 'Rahim']);
        Patient::factory()->create(['name' => 'Karim']);

        $this->get('/panel/patients')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Patients/Index')
            ->has('patients', 2)
            ->where('filters.q', '')
            ->where('search_engine', 'database')
            ->where('can.create', true));

        $this->get('/panel/patients?q=rah')->assertInertia(fn (AssertableInertia $p) => $p->has('patients', 1)->where('patients.0.name', 'Rahim'));
    }

    public function test_create_store_show_edit_update_round_trip(): void
    {
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel/patients/create')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Patients/Create')->has('branches')->where('primary', null));

        $this->post('/panel/patients', [
            'name' => 'মোঃ রহিম উদ্দিন', 'mobile' => '01712345678', 'gender' => 'male', 'age_years' => 45, 'blood_group' => 'O+',
            'address' => 'Dhanmondi', 'district' => 'Dhaka', 'national_id' => '1234567890', 'notes' => 'VIP', 'preferred_language' => 'bn',
        ])->assertRedirect();

        $patient = Patient::query()->where('mobile', '+8801712345678')->firstOrFail();
        $this->assertTrue($patient->dob_is_estimated);
        $this->assertSame('1234567890', $patient->national_id);
        $this->assertNotNull($patient->registered_by_user_id);

        $this->get('/panel/patients/'.$patient->public_id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Patients/Show')
            ->where('patient.public_id', $patient->public_id)
            ->where('patient.notes', 'VIP')
            ->where('patient.blood_group', 'O+')
            ->has('family', 0)
            ->has('timeline.data')
            ->where('vitals_available', true)
            ->where('can.update', true)
            ->where('can.merge', false));
        $this->assertAudited(AuditAction::View, $patient, ['screen' => 'panel.patients.show']);

        $this->get('/panel/patients/'.$patient->public_id.'/edit')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p->component('Patients/Edit')->where('patient.national_id', '1234567890'));
        $this->assertAudited(AuditAction::View, $patient, ['screen' => 'panel.patients.edit']);

        $this->put('/panel/patients/'.$patient->public_id, ['name' => 'মোঃ রহিম উদ্দিন', 'mobile' => '01712345678', 'dob' => '1981-02-03', 'address' => 'Mirpur'])
            ->assertRedirect('/panel/patients/'.$patient->public_id);
        $this->assertSame('1981-02-03', $patient->fresh()->dob?->toDateString());
        $this->assertFalse($patient->fresh()->dob_is_estimated);
        $this->assertSame('Mirpur', $patient->fresh()->address);
        $this->assertAudited(AuditAction::Update, $patient);
    }

    public function test_store_validates_mobile_and_requires_dob_or_age_and_reports_duplicates(): void
    {
        $this->actingAsStaff(Role::Receptionist);

        $this->post('/panel/patients', ['name' => 'X', 'mobile' => '12345', 'age_years' => 3])->assertSessionHasErrors('mobile');
        $this->post('/panel/patients', ['name' => 'X', 'mobile' => '01712345678'])->assertSessionHasErrors(['dob', 'age_years']);
        $this->post('/panel/patients', ['name' => 'X', 'mobile' => '01712345678', 'dob' => '2999-01-01'])->assertSessionHasErrors('dob');

        Patient::factory()->create(['name' => 'Dup', 'mobile' => '+8801712345678', 'dob' => '1990-01-01']);
        $this->post('/panel/patients', ['name' => 'dup', 'mobile' => '01712345678', 'dob' => '1990-01-01'])->assertSessionHasErrors('domain');
    }

    public function test_adding_a_family_member_links_them_to_the_owner(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $owner = Patient::factory()->create();

        $this->post('/panel/patients/'.$owner->public_id.'/dependents', ['name' => 'Junior', 'age_years' => 5, 'relation' => 'child', 'gender' => 'male'])->assertRedirect();

        $junior = Patient::query()->where('name', 'Junior')->firstOrFail();
        $this->assertSame($owner->mobile, $junior->mobile);
        $this->assertFalse($junior->is_mobile_owner);
        $this->assertSame($owner->name, $junior->guardian_name);
        $this->assertTrue($junior->primaryRelation?->primary->is($owner) ?? false);

        $this->get('/panel/patients/'.$owner->public_id)->assertInertia(fn (AssertableInertia $p) => $p->has('family', 1)->where('family.0.relation', 'child'));
        $this->get('/panel/patients/'.$junior->public_id)->assertInertia(fn (AssertableInertia $p) => $p->has('family', 1)->where('patient.primary.public_id', $owner->public_id));
    }

    public function test_allergy_condition_and_medication_endpoints_serve_inertia_forms_and_json(): void
    {
        $this->actingAsDoctor();
        $patient = Patient::factory()->create();
        $base = '/panel/patients/'.$patient->public_id;

        // JSON (the writer's XHR)
        $allergy = $this->postJson($base.'/allergies', ['allergen_type' => 'generic', 'generic_id' => 42, 'allergen_name' => 'Amoxicillin', 'severity' => 'severe', 'notes' => 'rash'])
            ->assertCreated()->assertJsonPath('data.allergen_name', 'Amoxicillin')->assertJsonPath('data.notes', 'rash')->json('data');
        $this->postJson($base.'/allergies', ['allergen_type' => 'generic', 'allergen_name' => 'no id'])->assertUnprocessable()->assertJsonValidationErrors('generic_id');
        $this->patchJson($base.'/allergies/'.$allergy['id'], ['allergen_type' => 'generic', 'generic_id' => 42, 'allergen_name' => 'Amoxicillin', 'severity' => 'moderate'])->assertOk()->assertJsonPath('data.severity', 'moderate');
        $this->deleteJson($base.'/allergies/'.$allergy['id'])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame(1, PatientAllergy::query()->count(), 'clinical rows are deactivated, never deleted');
        $this->getJson($base.'/allergies')->assertOk()->assertJsonCount(1, 'data');
        $this->assertAudited(AuditAction::Update, PatientAllergy::query()->firstOrFail());

        // Inertia (the Show tabs)
        $this->post($base.'/conditions', ['condition_name' => 'Hypertension', 'icd10_code' => 'i10', 'status' => 'chronic'])->assertRedirect();
        $condition = PatientCondition::query()->firstOrFail();
        $this->assertSame('I10', $condition->icd10_code);
        $this->delete($base.'/conditions/'.$condition->id)->assertRedirect();
        $this->assertSame('resolved', $condition->fresh()->status->value);
        $this->assertNotNull($condition->fresh()->resolved_date);

        $this->post($base.'/medications', ['generic_name' => 'Metformin', 'brand_name' => 'Comet', 'dose_text' => '1+0+1'])->assertRedirect();
        $medication = PatientMedication::query()->firstOrFail();
        $this->patch($base.'/medications/'.$medication->id, ['generic_name' => 'Metformin', 'dose_text' => '1+1+1'])->assertRedirect();
        $this->assertSame('1+1+1', $medication->fresh()->dose_text);
        $this->delete($base.'/medications/'.$medication->id)->assertRedirect();
        $this->assertFalse($medication->fresh()->is_active);
        $this->assertNotNull($medication->fresh()->ended_on);

        // Child rows are scoped to their patient.
        $other = Patient::factory()->create();
        $this->deleteJson('/panel/patients/'.$other->public_id.'/medications/'.$medication->id)->assertNotFound();

        // Accountants may read but not write clinical rows.
        $this->actingAsStaff(Role::Accountant);
        $this->getJson($base.'/allergies')->assertOk();
        $this->postJson($base.'/allergies', ['allergen_type' => 'food', 'allergen_name' => 'Egg'])->assertForbidden();
    }

    public function test_consents_append_only_and_merge_is_admin_only(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $patient = Patient::factory()->create();

        $this->post('/panel/patients/'.$patient->public_id.'/consents', ['type' => 'sms', 'status' => 'granted', 'policy_version' => '2026-01', 'channel' => 'counter', 'text_shown' => 'SMS consent text'])->assertRedirect();
        $this->post('/panel/patients/'.$patient->public_id.'/consents', ['type' => 'sms', 'status' => 'revoked', 'policy_version' => '2026-01', 'channel' => 'counter'])->assertRedirect();
        $this->assertSame(2, PatientConsent::query()->where('patient_id', $patient->id)->count());
        $this->assertSame('SMS consent text', PatientConsent::query()->oldest('id')->firstOrFail()->evidence['text_shown']);
        $this->assertNotNull(PatientConsent::query()->firstOrFail()->captured_by_user_id);

        $loser = Patient::factory()->create();
        $this->post('/panel/patients/'.$patient->public_id.'/merge', ['loser_public_id' => $loser->public_id])->assertForbidden();

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->post('/panel/patients/'.$patient->public_id.'/merge', ['loser_public_id' => $loser->public_id, 'reason' => 'dup'])->assertRedirect('/panel/patients/'.$patient->public_id);
        $this->assertNull(Patient::query()->find($loser->id));
    }

    public function test_panel_pages_require_a_staff_session(): void
    {
        $this->get('/panel/patients')->assertRedirect('/panel/login');
    }
}
