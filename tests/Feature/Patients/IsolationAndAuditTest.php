<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Patients\Actions\MergePatients;
use App\Domain\Patients\Actions\UpdatePatient;
use App\Domain\Patients\Data\PatientData;
use App\Domain\Shared\Actor;
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

    /**
     * A family link is what grants a guardian portal access to a dependant's clinical records, so it must be as
     * auditable to leave a household as to join one. Both actions used to drop the link through the query builder,
     * which fires no model event: the trail recorded households being joined and never left.
     */
    public function test_leaving_a_household_is_audited_exactly_like_joining_one(): void
    {
        $this->asTenant('a');
        $staff = $this->actingAsStaff(Role::HospitalAdmin);

        $primary = Patient::factory()->create(['mobile' => '+8801710000501', 'is_mobile_owner' => true]);
        $dependent = Patient::factory()->dependentOf($primary)->create(['name' => 'Dependant']);
        $link = PatientRelation::query()->where('dependent_patient_id', $dependent->id)->firstOrFail();

        $this->assertAudited(AuditAction::Create, $link);

        // Moving the dependant to their own number takes them out of the household.
        app(UpdatePatient::class)->handle(
            $dependent,
            new PatientData(name: 'Dependant', mobile: '+8801710000502'),
            Actor::user($staff->id),
        );

        $this->assertFalse(PatientRelation::query()->whereKey($link->id)->exists());

        $deleted = $this->assertAudited(AuditAction::Delete, $link);
        $this->assertSame(AuditActorType::User, $deleted->actor_type);
        $this->assertSame($staff->id, $deleted->actor_id, 'the trail names the staff member who broke the link');
        $this->assertSame($dependent->id, $deleted->patient_id, 'and files it under the dependant whose records it guarded');
        $this->assertSame($primary->id, $deleted->before['primary_patient_id'] ?? null);
        $this->assertSame($dependent->id, $deleted->before['dependent_patient_id'] ?? null);
    }

    public function test_a_merge_audits_every_family_link_it_dissolves_or_moves(): void
    {
        $this->asTenant('a');
        $staff = $this->actingAsStaff(Role::HospitalAdmin);

        $winner = Patient::factory()->create(['mobile' => '+8801710000601', 'is_mobile_owner' => true]);
        $loser = Patient::factory()->create(['mobile' => '+8801710000602', 'is_mobile_owner' => true]);
        $childOfLoser = Patient::factory()->dependentOf($loser)->create(['name' => 'Child of loser']);

        $movedLink = PatientRelation::query()->where('dependent_patient_id', $childOfLoser->id)->firstOrFail();

        app(MergePatients::class)->handle($winner, $loser, Actor::user($staff->id), 'duplicate registration');

        // The child now hangs off the winner, and the move left a row naming who did it.
        $this->assertSame($winner->id, PatientRelation::query()->whereKey($movedLink->id)->value('primary_patient_id'));

        $updated = $this->assertAudited(AuditAction::Update, $movedLink);
        $this->assertSame($staff->id, $updated->actor_id);
        $this->assertSame($loser->id, $updated->before['primary_patient_id'] ?? null);
        $this->assertSame($winner->id, $updated->after['primary_patient_id'] ?? null);

        // And the summarising row for the bulk repoint says enough to reconstruct the merge.
        $summary = $this->assertAudited(AuditAction::Update, $winner, ['loser_id' => $loser->id]);
        $this->assertSame('duplicate registration', $summary->context['reason']);
        $this->assertSame(1, $summary->context['family_links_moved']);
        $this->assertSame($loser->public_id, $summary->after['merged_from'] ?? null);
    }
}
