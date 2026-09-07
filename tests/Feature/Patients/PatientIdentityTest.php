<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Patients\Actions\CreatePatient;
use App\Domain\Patients\Actions\LinkFamilyMember;
use App\Domain\Patients\Actions\MergePatients;
use App\Domain\Patients\Actions\UpdatePatient;
use App\Domain\Patients\Data\PatientData;
use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Domain\Patients\Exceptions\CannotMergeSelf;
use App\Domain\Patients\Exceptions\DependentAlreadyLinked;
use App\Domain\Patients\Exceptions\DuplicatePatient;
use App\Domain\Shared\Actor;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientRelation;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class PatientIdentityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_same_mobile_with_different_names_become_dependents_of_the_first_registrant(): void
    {
        $create = app(CreatePatient::class);
        $father = $create->handle(new PatientData(name: 'Md Rahim', mobile: '01712345678', ageYears: 45), Actor::system());
        $son = $create->handle(new PatientData(name: 'Md Karim', mobile: '017 1234 5678', ageYears: 12, relation: RelationType::Child), Actor::system());

        $this->assertTrue($father->is_mobile_owner);
        $this->assertFalse($son->is_mobile_owner);
        $this->assertSame('+8801712345678', $son->mobile);
        $this->assertTrue($son->primaryRelation?->primary->is($father) ?? false);
        $this->assertSame(RelationType::Child, $son->primaryRelation->relation);
        $this->assertSame([$father->id, $son->id], Patient::query()->household('+8801712345678')->pluck('id')->all());
    }

    public function test_same_mobile_name_and_dob_is_a_duplicate(): void
    {
        $create = app(CreatePatient::class);
        $create->handle(new PatientData(name: 'Fatema Khatun', mobile: '01812345678', dob: CarbonImmutable::parse('1990-05-01')), Actor::system());

        $this->expectException(DuplicatePatient::class);
        $create->handle(new PatientData(name: '  fatema khatun ', mobile: '+8801812345678', dob: CarbonImmutable::parse('1990-05-01')), Actor::system());
    }

    public function test_the_identity_index_is_the_ultimate_guard_including_the_unknown_dob_case(): void
    {
        Patient::factory()->create(['name' => 'Abdul Karim', 'mobile' => '+8801912345678', 'dob' => null]);

        $this->expectException(QueryException::class);
        DB::table('patients')->insert([
            'public_id' => str_repeat('0', 26), 'tenant_id' => 9001, 'patient_code' => 'P-999999', 'name' => 'ABDUL KARIM ', 'mobile' => '+8801912345678',
            'dob' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_same_name_with_a_different_dob_is_a_different_person(): void
    {
        $create = app(CreatePatient::class);
        $senior = $create->handle(new PatientData(name: 'Md Rahim', mobile: '01712345679', dob: CarbonImmutable::parse('1970-01-01')), Actor::system());
        $junior = $create->handle(new PatientData(name: 'Md Rahim', mobile: '01712345679', dob: CarbonImmutable::parse('2010-01-01')), Actor::system());

        $this->assertNotSame($senior->id, $junior->id);
        $this->assertFalse($junior->is_mobile_owner);
    }

    public function test_soft_deleted_rows_free_their_identity(): void
    {
        $create = app(CreatePatient::class);
        $first = $create->handle(new PatientData(name: 'Nasrin', mobile: '01512345678'), Actor::system());
        $first->delete();

        $again = $create->handle(new PatientData(name: 'Nasrin', mobile: '01512345678'), Actor::system());

        $this->assertNotSame($first->id, $again->id);
        $this->assertTrue($again->is_mobile_owner);
    }

    public function test_the_mobile_check_rejects_non_bd_numbers_at_the_database(): void
    {
        $this->expectException(QueryException::class);
        Patient::factory()->create(['mobile' => '+447700900123']);
    }

    public function test_changing_the_mobile_moves_the_person_into_the_other_household(): void
    {
        $owner = Patient::factory()->create(['mobile' => '+8801711111111']);
        $mover = Patient::factory()->create(['mobile' => '+8801722222222', 'name' => 'Mover']);

        $updated = app(UpdatePatient::class)->handle($mover, new PatientData(name: 'Mover', mobile: '01711111111', relation: RelationType::Spouse), Actor::system());

        $this->assertSame('+8801711111111', $updated->mobile);
        $this->assertFalse($updated->is_mobile_owner);
        $this->assertTrue($updated->primaryRelation?->primary->is($owner) ?? false);
        $this->assertSame(RelationType::Spouse, $updated->primaryRelation->relation);
    }

    public function test_link_family_member_refuses_a_second_primary(): void
    {
        $owner = Patient::factory()->create();
        $other = Patient::factory()->create();
        $link = app(LinkFamilyMember::class);

        $relation = $link->handle($owner, $other, RelationType::Parent, Actor::system());
        $this->assertSame($owner->id, $relation->primary_patient_id);

        $this->expectException(DependentAlreadyLinked::class);
        $link->handle(Patient::factory()->create(), $other, RelationType::Sibling, Actor::system());
    }

    public function test_merge_repoints_children_soft_deletes_the_loser_and_audits(): void
    {
        $this->actingAsStaff('hospital_admin');
        $winner = Patient::factory()->create(['visit_count' => 2]);
        $loser = Patient::factory()->create(['visit_count' => 3]);
        $allergy = PatientAllergy::factory()->for($loser)->create();
        $dependent = Patient::factory()->dependentOf($loser)->create();
        AuditLog::view($loser);

        $merged = app(MergePatients::class)->handle($winner, $loser, Actor::system(), 'same person, typo in name');

        $this->assertSame($winner->id, $allergy->fresh()?->patient_id);
        $this->assertSame($winner->id, PatientRelation::query()->where('dependent_patient_id', $dependent->id)->value('primary_patient_id'));
        $this->assertSame(5, $merged->visit_count);
        $this->assertNotNull(Patient::withTrashed()->find($loser->id)?->deleted_at);
        $this->assertNull(Patient::query()->find($loser->id));
        $this->assertSame(0, AuditLog::query()->where('patient_id', $loser->id)->where('action', 'view')->count(), 'pre-merge audit rows repointed to the winner');
        $this->assertTrue(AuditLog::query()->where('patient_id', $winner->id)->where('action', 'view')->exists());
        $this->assertTrue(AuditLog::query()->where('patient_id', $winner->id)->where('action', 'update')->where('context->reason', 'same person, typo in name')->exists());

        $this->expectException(CannotMergeSelf::class);
        app(MergePatients::class)->handle($winner, $winner, Actor::system());
    }
}
