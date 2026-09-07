<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Support\Clock;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\Models\Role as SpatieRole;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * BRIEF §5.L + §5.N: "an accountant sees money, a doctor sees their own clinical numbers and not the clinic's
 * revenue, a hospital admin sees everything". A receptionist holds no `reports.view` at all.
 */
final class ReportPermissionTest extends TestCase
{
    use ReportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    public function test_the_role_matrix_grants_exactly_the_report_permissions_each_role_needs(): void
    {
        $doctor = $this->actingAsDoctor();
        $this->assertTrue($doctor->can(Permission::ReportsView->value));
        $this->assertTrue($doctor->can(Permission::ReportsClinicalView->value));
        $this->assertTrue($doctor->can(Permission::ReportsExport->value));
        $this->assertFalse($doctor->can(Permission::ReportsFinancialView->value), 'a doctor must not see the clinic\'s revenue');
        $this->assertFalse($doctor->can(Permission::ReportsAllDoctorsView->value));

        $accountant = $this->actingAsStaff(Role::Accountant);
        $this->assertTrue($accountant->can(Permission::ReportsFinancialView->value));
        $this->assertTrue($accountant->can(Permission::ReportsAllDoctorsView->value));
        $this->assertFalse($accountant->can(Permission::ReportsClinicalView->value), 'money, not clinical detail');

        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $this->assertFalse($receptionist->can(Permission::ReportsView->value));

        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        foreach ([Permission::ReportsView, Permission::ReportsFinancialView, Permission::ReportsClinicalView, Permission::ReportsAllDoctorsView, Permission::ReportsExport] as $permission) {
            $this->assertTrue($admin->can($permission->value));
        }
    }

    public function test_a_doctors_scope_is_forced_onto_the_filters_so_the_query_string_cannot_widen_it(): void
    {
        $this->seedReportFixture();
        $user = $this->actingAsDoctor();
        $doctorId = $user->doctor?->id;

        $scope = app(ReportScopeResolver::class)->for($user);
        $this->assertFalse($scope->allDoctors);
        $this->assertSame($doctorId, $scope->doctorId);

        // Someone hand-edits ?doctor= to another doctor's public id — the scope overwrites it.
        $tampered = ReportFilters::forDays('2026-03-01', '2026-03-10')->withDoctor($this->rahman->id);
        $this->assertSame($doctorId, $scope->apply($tampered)->doctorId);
    }

    public function test_the_section_and_each_family_are_gated_over_http(): void
    {
        $this->seedReportFixture();

        $this->actingAsStaff(Role::Receptionist);
        $this->get(route('panel.reports.index', [], false))->assertForbidden();

        $this->actingAsDoctor();
        $this->get(route('panel.reports.index', [], false))->assertOk();
        $this->get(route('panel.reports.show', ['report' => 'clinical'], false))->assertOk();
        $this->get(route('panel.reports.show', ['report' => 'revenue'], false))->assertForbidden();

        $this->actingAsStaff(Role::Accountant);
        $this->get(route('panel.reports.show', ['report' => 'revenue'], false))->assertOk();
        $this->get(route('panel.reports.show', ['report' => 'clinical'], false))->assertForbidden();

        $this->actingAsStaff(Role::HospitalAdmin);
        foreach (['appointments', 'wait-times', 'revenue', 'patients', 'clinical', 'peak-hours'] as $report) {
            $this->get(route('panel.reports.show', ['report' => $report], false))->assertOk();
        }
    }

    public function test_exporting_is_its_own_permission(): void
    {
        $this->seedReportFixture();
        $this->actingAsStaff(Role::Accountant);
        $this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'csv'], false))->assertOk();

        // Strip the export permission from the role and the same user may still READ the report but not take it.
        SpatieRole::findByName(Role::Accountant->value, 'web')->revokePermissionTo(Permission::ReportsExport->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->get(route('panel.reports.show', ['report' => 'appointments'], false))->assertOk();
        $this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'csv'], false))->assertForbidden();

        SpatieRole::findByName(Role::Accountant->value, 'web')->givePermissionTo(Permission::ReportsExport->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->get(route('panel.reports.export', ['report' => 'revenue', 'format' => 'csv'], false))->assertOk();
        $this->get(route('panel.reports.export', ['report' => 'clinical', 'format' => 'csv'], false))->assertForbidden();
    }

    public function test_the_page_only_offers_the_reports_the_scope_allows(): void
    {
        $this->seedReportFixture();
        $this->actingAsDoctor();

        $this->get(route('panel.reports.index', [], false))->assertInertia(
            fn (AssertableInertia $page) => $page
                ->component('Reports/Dashboard')
                ->where('scope.financial', false)
                ->where('scope.clinical', true)
                ->where('scope.all_doctors', false)
                ->where('data.money', null)
                ->where('scope.reports', function (mixed $reports): bool {
                    // Inertia hands array props back as a Collection here.
                    $values = $reports instanceof Collection ? $reports->all() : (array) $reports;

                    return ! in_array('revenue', $values, true) && in_array('clinical', $values, true);
                }),
        );
    }

    public function test_a_doctors_numbers_are_only_their_own(): void
    {
        $this->seedReportFixture();
        $user = $this->actingAsDoctor();
        // Point the logged-in doctor row at Dr Rahman's sessions by renaming: the scope resolves the USER's
        // own doctor row, which has no sessions, so every count must be zero rather than the clinic's.
        $this->get(route('panel.reports.show', ['report' => 'appointments', 'from' => '2026-03-01', 'to' => '2026-03-10'], false))
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Reports/Appointments')
                ->where('data.totals.booked', 0)
                ->where('filters.doctor_id', $user->doctor?->id));
    }
}
