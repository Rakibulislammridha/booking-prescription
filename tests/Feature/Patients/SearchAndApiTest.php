<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Patients\Services\PatientSearch;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\PatientMedication;
use Tests\TestCase;

/** SCOUT_DRIVER=null in tests → the Postgres fallback of PatientSearch is what runs here. */
final class SearchAndApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_database_fallback_finds_by_mobile_name_code_and_public_id(): void
    {
        $rahim = Patient::factory()->create(['name' => 'Md Rahim Uddin', 'mobile' => '+8801712345678']);
        $karim = Patient::factory()->create(['name' => 'Abdul Karim', 'mobile' => '+8801812345678']);
        $bangla = Patient::factory()->create(['name' => 'ফাতেমা খাতুন', 'mobile' => '+8801912345678']);
        $search = app(PatientSearch::class);

        $this->assertFalse($search->usesMeilisearch());
        $this->assertSame([$rahim->id], $search->search('01712345678')->pluck('id')->all());
        $this->assertSame([$rahim->id], $search->search('+88 017-1234 5678')->pluck('id')->all());
        $this->assertSame([$rahim->id], $search->search('0171234')->pluck('id')->all(), 'mobile prefix');
        $this->assertSame([$karim->id], $search->search('karim')->pluck('id')->all());
        $this->assertSame([$rahim->id], $search->search('RAHIM ud')->pluck('id')->all());
        $this->assertSame([$bangla->id], $search->search('ফাতেমা')->pluck('id')->all());
        $this->assertSame([$karim->id], $search->search($karim->patient_code)->pluck('id')->all());
        $this->assertSame([$karim->id], $search->search('p'.(int) substr($karim->patient_code, 2))->pluck('id')->all());
        $this->assertSame([$bangla->id], $search->search($bangla->public_id)->pluck('id')->all());
        $this->assertCount(0, $search->search('nobody'));
        $this->assertCount(3, $search->search(''), 'empty query = recent');
    }

    public function test_recent_orders_by_last_visit_then_newest_and_household_lists_owner_first(): void
    {
        $old = Patient::factory()->create(['last_visit_at' => now()->subDays(30)]);
        $never = Patient::factory()->create();
        $recent = Patient::factory()->create(['last_visit_at' => now()->subDay()]);
        $child = Patient::factory()->dependentOf($old)->create();
        $search = app(PatientSearch::class);

        $this->assertSame([$recent->id, $old->id, $child->id, $never->id], $search->recent()->pluck('id')->all());
        $this->assertSame([$old->id, $child->id], $search->household(substr($old->mobile, 3))->pluck('id')->all());
        $this->assertCount(0, $search->household('12345'));
    }

    public function test_api_quick_search_recent_and_by_mobile_return_summary_rows_without_secrets(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $owner = Patient::factory()->withEncryptedFields()->create(['name' => 'Rahim', 'mobile' => '+8801712345678', 'address' => 'Dhanmondi']);
        Patient::factory()->dependentOf($owner)->create(['name' => 'Junior']);

        $this->getJson('/api/patients/search?q=rahim')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.public_id', $owner->public_id)
            ->assertJsonPath('data.0.mobile_local', '01712345678')
            ->assertJsonPath('meta.engine', 'database')
            ->assertJsonMissingPath('data.0.national_id')
            ->assertJsonMissingPath('data.0.notes')
            ->assertJsonMissingPath('data.0.address');

        $this->getJson('/api/patients/recent?limit=10')->assertOk()->assertJsonCount(2, 'data');

        $this->getJson('/api/patients/by-mobile/01712345678')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.is_mobile_owner', true)
            ->assertJsonPath('data.1.relation', 'child')
            ->assertJsonPath('meta.mobile', '+8801712345678');

        $this->getJson('/api/patients/by-mobile/999')->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('meta.valid', false);
    }

    public function test_api_summary_serves_the_writer_and_is_audited(): void
    {
        $this->actingAsDoctor();
        $owner = Patient::factory()->create(['name' => 'Head']);
        $patient = Patient::factory()->dependentOf($owner)->create(['dob' => now()->subYears(30)->subMonths(2)->toDateString(), 'gender' => 'female']);
        PatientAllergy::factory()->for($patient)->create(['allergen_name' => 'Penicillin', 'is_active' => true]);
        PatientAllergy::factory()->for($patient)->create(['allergen_name' => 'Old', 'is_active' => false]);
        PatientCondition::factory()->for($patient)->create(['icd10_code' => 'Z33.1', 'condition_name' => 'Pregnant', 'status' => 'active']);
        PatientCondition::factory()->for($patient)->create(['icd10_code' => 'N18.9', 'condition_name' => 'CKD', 'status' => 'chronic']);
        PatientCondition::factory()->for($patient)->resolved()->create(['icd10_code' => 'K76.9', 'condition_name' => 'old liver']);
        PatientMedication::factory()->for($patient)->create(['generic_name' => 'Metformin']);
        PatientMedication::factory()->for($patient)->stopped()->create(['generic_name' => 'Stopped']);
        PatientConsent::factory()->for($patient)->create(['type' => 'sms', 'status' => 'granted', 'occurred_at' => now()->subDay()]);
        PatientConsent::factory()->for($patient)->revoked()->create(['type' => 'sms', 'occurred_at' => now()]);
        PatientConsent::factory()->for($patient)->create(['type' => 'whatsapp', 'status' => 'granted']);

        $this->getJson('/api/patients/'.$patient->public_id.'/summary')
            ->assertOk()
            ->assertJsonPath('data.age_years', 30)
            ->assertJsonPath('data.sex', 'female')
            ->assertJsonPath('data.family_head.name', 'Head')
            ->assertJsonPath('data.family_head.relation', 'child')
            ->assertJsonCount(1, 'data.allergies')
            ->assertJsonCount(2, 'data.conditions')
            ->assertJsonCount(1, 'data.medications')
            ->assertJsonPath('data.flags.pregnant', true)
            ->assertJsonPath('data.flags.renal', true)
            ->assertJsonPath('data.flags.hepatic', false)
            ->assertJsonPath('data.flags.lactating', false)
            ->assertJsonPath('data.consents.sms', false)
            ->assertJsonPath('data.consents.whatsapp', true)
            ->assertJsonPath('data.consents.data_sharing', false)
            ->assertJsonPath('data.recent_visits', []);

        $this->assertAudited(AuditAction::View, $patient, ['section' => 'summary']);
    }

    public function test_api_endpoints_require_a_staff_session_and_the_view_permission(): void
    {
        $this->getJson('/api/patients/search?q=x')->assertUnauthorized();

        $user = $this->actingAsStaff(Role::Receptionist);
        $user->syncRoles([]);
        $this->getJson('/api/patients/search?q=x')->assertForbidden();
        $this->getJson('/api/patients/recent')->assertForbidden();
    }
}
