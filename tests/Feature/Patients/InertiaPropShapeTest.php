<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;
use App\Models\Tenant\PatientCondition;
use App\Models\Tenant\PatientDocument;
use App\Models\Tenant\PatientMedication;
use Inertia\Inertia;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Inertia's PropsResolver walks the whole prop tree and turns any nested `Responsable` into its RESPONSE body, so
 * a resource collection left un-resolved inside another resource arrives at the page as `{"data": [...]}` — not
 * the array `models.d.ts` declares. `Patients/Show` calls `.filter` on it, so the record page rendered a blank
 * screen for every patient. These assertions are shaped to fail on the object form, not just on a missing key.
 */
final class InertiaPropShapeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_the_patient_record_props_are_arrays_the_page_can_iterate(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $patient = Patient::factory()->create();
        PatientAllergy::factory()->count(2)->for($patient)->create();
        PatientCondition::factory()->for($patient)->create();
        PatientMedication::factory()->count(3)->for($patient)->create();
        PatientDocument::factory()->for($patient)->create();

        $response = $this->get('/panel/patients/'.$patient->public_id)->assertOk();

        // `has($key, $count)` fails on `{"data": [...]}` — that shape has one key, not N entries.
        $response->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Patients/Show')
            ->has('patient.allergies', 2)
            ->has('patient.conditions', 1)
            ->has('patient.medications', 3)
            ->has('patient.documents', 1)
            ->has('patient.consents', 0)
            ->has('vitals_trend'));

        // And prove it at the JSON level, which is what the browser actually parses.
        $props = $this->inertiaProps('/panel/patients/'.$patient->public_id);

        foreach (['allergies', 'conditions', 'medications', 'documents', 'consents'] as $key) {
            $value = $props['patient'][$key];
            $this->assertIsArray($value, "patient.{$key} must be an array");
            $this->assertTrue(array_is_list($value), "patient.{$key} arrived as an object; the page calls .filter on it");
        }
    }

    /** @return array<string, mixed> */
    private function inertiaProps(string $url): array
    {
        /** @var array{props: array<string, mixed>} $page */
        $page = $this->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia::getVersion()])->get($url)->assertOk()->json();

        return $page['props'];
    }
}
