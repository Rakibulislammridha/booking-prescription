<?php

declare(strict_types=1);

namespace Tests\Feature\Patients;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Patients\Contracts\PatientAccessResolver;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Tests\TestCase;

final class PolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_role_matrix_drives_patient_abilities(): void
    {
        $patient = Patient::factory()->create();
        $matrix = [
            Role::HospitalAdmin->value => ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'merge' => true, 'export' => true, 'manageClinical' => true, 'uploadDocument' => true, 'recordConsent' => true],
            Role::Receptionist->value => ['viewAny' => true, 'view' => true, 'create' => true, 'update' => true, 'merge' => false, 'export' => false, 'manageClinical' => true, 'uploadDocument' => true, 'recordConsent' => true],
            Role::Doctor->value => ['viewAny' => true, 'view' => true, 'create' => false, 'update' => false, 'merge' => false, 'export' => false, 'manageClinical' => true, 'uploadDocument' => true, 'recordConsent' => false],
            Role::Accountant->value => ['viewAny' => true, 'view' => true, 'create' => false, 'update' => false, 'merge' => false, 'export' => false, 'manageClinical' => false, 'uploadDocument' => false, 'recordConsent' => false],
        ];

        foreach ($matrix as $role => $abilities) {
            $user = User::factory()->withRole($role)->create();

            if ($role === Role::Doctor->value) {
                Doctor::factory()->create(['user_id' => $user->id, 'name' => $user->name]);   // row-level access resolves through `doctors`
            }

            foreach ($abilities as $ability => $allowed) {
                $subject = in_array($ability, ['viewAny', 'create'], true) ? Patient::class : $patient;
                $this->assertSame($allowed, $user->can($ability, $subject), "{$role} {$ability}");
            }
        }
    }

    public function test_a_user_without_the_view_permission_or_inactive_sees_nothing(): void
    {
        $patient = Patient::factory()->create();
        $nobody = User::factory()->create();
        $this->assertFalse($nobody->can('view', $patient));
        $this->assertFalse($nobody->can('viewAny', Patient::class));

        $inactive = User::factory()->withRole(Role::HospitalAdmin)->inactive()->create();
        $this->assertFalse($inactive->can('view', $patient));
    }

    public function test_the_default_resolver_grants_doctors_every_patient_until_visit_data_exists(): void
    {
        $doctor = $this->actingAsDoctor();
        $patient = Patient::factory()->create();

        $this->assertTrue(app(PatientAccessResolver::class)->canAccess($doctor, $patient));
        $this->assertTrue($doctor->can('view', $patient));
        $this->get('/panel/patients/'.$patient->public_id)->assertOk();
    }

    public function test_a_rebound_resolver_is_honoured_by_the_policy_and_the_search(): void
    {
        $this->app->bind(PatientAccessResolver::class, fn () => new class implements PatientAccessResolver
        {
            public function canAccess(User $user, Patient $patient): bool
            {
                return $patient->name === 'Mine';
            }

            public function constrain(User $user, Builder $query): void
            {
                $query->where('name', 'Mine');
            }
        });

        $this->actingAsDoctor();
        $mine = Patient::factory()->create(['name' => 'Mine']);
        $theirs = Patient::factory()->create(['name' => 'Theirs']);

        $this->get('/panel/patients/'.$mine->public_id)->assertOk();
        $this->get('/panel/patients/'.$theirs->public_id)->assertForbidden();
        $this->getJson('/api/patients/recent')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.public_id', $mine->public_id);
        $this->getJson('/api/patients/'.$theirs->public_id.'/summary')->assertForbidden();
    }

    public function test_prescriptions_view_any_overrides_the_row_level_rule(): void
    {
        $doctor = $this->actingAsDoctor();
        $doctor->givePermissionTo(Permission::PrescriptionsViewAny->value);

        $this->assertTrue($doctor->can('view', Patient::factory()->create()));
    }
}
