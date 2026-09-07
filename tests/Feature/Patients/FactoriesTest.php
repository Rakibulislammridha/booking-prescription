<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientConsent;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\PatientMedication;
use App\Models\Tenant\PatientOtpCode;
use App\Models\Tenant\PatientRelation;
use Tests\TestCase;

final class FactoriesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_every_patient_factory_creates_a_row_with_the_schema_defaults(): void
    {
        $patient = Patient::factory()->withEncryptedFields()->create();

        $this->assertSame(26, strlen($patient->public_id));
        $this->assertMatchesRegularExpression('/^P-\d{6}$/', $patient->patient_code);
        $this->assertSame(9001, $patient->tenant_id);
        $this->assertMatchesRegularExpression('/^\+8801[3-9]\d{8}$/', $patient->mobile);
        $this->assertSame(mb_strtolower(trim($patient->name)), $patient->fresh()?->name_normalized);
        $this->assertTrue($patient->is_mobile_owner);
        $this->assertNotNull($patient->age_text);

        $this->assertInstanceOf(PatientAllergy::class, PatientAllergy::factory()->for($patient)->create());
        $this->assertInstanceOf(PatientAllergy::class, PatientAllergy::factory()->for($patient)->generic(12)->create());
        $this->assertInstanceOf(PatientCondition::class, PatientCondition::factory()->for($patient)->create());
        $this->assertInstanceOf(PatientCondition::class, PatientCondition::factory()->for($patient)->resolved()->create());
        $this->assertInstanceOf(PatientMedication::class, PatientMedication::factory()->for($patient)->create());
        $this->assertInstanceOf(PatientDocument::class, PatientDocument::factory()->for($patient)->create());
        $this->assertInstanceOf(PatientConsent::class, PatientConsent::factory()->for($patient)->signed()->create());
        $this->assertInstanceOf(PatientOtpCode::class, PatientOtpCode::factory()->create(['mobile' => $patient->mobile, 'patient_id' => $patient->id]));

        $dependent = Patient::factory()->dependentOf($patient, RelationType::Child)->create();
        $this->assertSame($patient->mobile, $dependent->mobile);
        $this->assertFalse($dependent->is_mobile_owner);
        $this->assertInstanceOf(PatientRelation::class, $dependent->primaryRelation);
        $this->assertTrue($dependent->primaryRelation->primary->is($patient));
        $this->assertTrue($patient->dependents()->first()?->is($dependent) ?? false);
        $this->assertSame(RelationType::Child, $patient->dependentRelations()->first()?->relation);
    }

    public function test_patient_codes_come_from_the_per_schema_sequence(): void
    {
        $a = Patient::factory()->create();
        $b = Patient::factory()->create();

        $this->assertSame((int) substr($a->patient_code, 2) + 1, (int) substr($b->patient_code, 2));
    }

    public function test_estimated_age_state_stores_an_estimated_dob(): void
    {
        $patient = Patient::factory()->estimatedAge(40)->create();

        $this->assertTrue($patient->dob_is_estimated);
        $this->assertContains($patient->age_years, [39, 40]);
    }
}
