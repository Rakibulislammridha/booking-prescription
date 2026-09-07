<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\PatientMedication;
use App\Models\Tenant\PatientOtpCode;
use App\Models\Tenant\PatientRelation;
use Tests\TestCase;

final class IsolationAndAuditTest extends TestCase
{
    public function test_every_patients_table_is_tenant_isolated(): void
    {
        $this->assertTenantIsolated('patients', fn () => Patient::factory()->count(2)->create());
        $this->assertTenantIsolated('patient_relations', fn () => PatientRelation::factory()->create());
        $this->assertTenantIsolated('patient_allergies', fn () => PatientAllergy::factory()->create());
        $this->assertTenantIsolated('patient_conditions', fn () => PatientCondition::factory()->create());
        $this->assertTenantIsolated('patient_medications', fn () => PatientMedication::factory()->create());
        $this->assertTenantIsolated('patient_documents', fn () => PatientDocument::factory()->create());
        $this->assertTenantIsolated('patient_consents', fn () => PatientConsent::factory()->create());
        $this->assertTenantIsolated('patient_otp_codes', fn () => PatientOtpCode::factory()->create());
    }

    public function test_a_patient_of_tenant_a_is_invisible_in_tenant_b_by_public_id_too(): void
    {
        $this->asTenant('a');
        $patient = Patient::factory()->create();

        $this->asTenant('b');
        $this->assertNull(Patient::query()->wherePublicId($patient->public_id)->first());
        $this->assertNull(Patient::query()->where('mobile', $patient->mobile)->first());

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->get('/panel/patients/'.$patient->public_id)->assertNotFound();
    }

    public function test_a_foreign_tenant_id_can_never_be_written(): void
    {
        $this->asTenant('a');

        $this->expectException(\LogicException::class);
        Patient::factory()->create(['tenant_id' => 9002]);
    }

    public function test_clinical_writes_and_views_produce_audit_rows_linked_to_the_patient(): void
    {
        $this->asTenant('a');
        $staff = $this->actingAsStaff(Role::Receptionist);
        $patient = Patient::factory()->create(['name' => 'Before']);

        $created = $this->assertAudited(AuditAction::Create, $patient);
        $this->assertSame($patient->id, $created->patient_id);
        $this->assertSame(AuditActorType::User, $created->actor_type);
        $this->assertSame($staff->id, $created->actor_id);

        $patient->update(['name' => 'After']);
        $updated = $this->assertAudited(AuditAction::Update, $patient);
        $this->assertSame(['name' => 'Before'], $updated->before);
        $this->assertSame(['name' => 'After'], $updated->after);

        $allergy = PatientAllergy::factory()->for($patient)->create();
        $this->assertSame($patient->id, $this->assertAudited(AuditAction::Create, $allergy)->patient_id);

        $dependent = Patient::factory()->dependentOf($patient)->create();
        $relation = PatientRelation::query()->where('dependent_patient_id', $dependent->id)->firstOrFail();
        $this->assertSame($dependent->id, $this->assertAudited(AuditAction::Create, $relation)->patient_id);

        $this->assertNotAudited(AuditAction::Create, PatientOtpCode::factory()->create());

        $this->get('/panel/patients/'.$patient->public_id)->assertOk();
        $view = $this->assertAudited(AuditAction::View, $patient, ['screen' => 'panel.patients.show']);
        $this->assertSame($patient->id, $view->patient_id);
        $this->assertSame('panel.patients.show', $view->context['route']);
        $this->assertCount(1, AuditLog::query()->where('auditable_id', $patient->id)->where('action', 'view')->get());
    }
}
