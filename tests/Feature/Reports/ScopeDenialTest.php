<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportScope;
use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Queries\AppointmentVolumeQuery;
use App\Domain\Reports\Services\ReportData;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * A scope that is restricted to one doctor but cannot say WHICH doctor must show nothing.
 *
 * The trap is that the natural encoding of "restricted, but to nobody" — `allDoctors = false` with a null
 * `doctorId` — is indistinguishable, to every query object, from "no doctor filter at all", because they all
 * apply it as `if ($filters->doctorId !== null)`. So the most restricted scope in the system silently became
 * the least restricted one, and a login holding the doctor role without a `doctors` row read the whole clinic.
 */
final class ScopeDenialTest extends TestCase
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

    public function test_a_doctor_login_with_no_doctor_row_reads_nothing_rather_than_the_whole_clinic(): void
    {
        $this->seedReportFixture();

        // The doctor role assigned before the `doctors` row exists — or after it was removed.
        $user = $this->actingAsStaff(Role::Doctor);
        $this->assertNull($user->doctor, 'precondition: this login is not linked to a doctor row');

        $scope = app(ReportScopeResolver::class)->for($user);
        $this->assertTrue($scope->deniesAll());

        $filters = $scope->apply(ReportFilters::forDays('2026-03-01', '2026-03-10'));

        // Even a query object used directly, bypassing ReportData's own gate, sees nothing.
        $this->assertSame(0, app(AppointmentVolumeQuery::class)->summary($filters)['totals']['booked']);
    }

    public function test_such_a_login_is_refused_the_section_instead_of_being_shown_zeroes(): void
    {
        $this->seedReportFixture();
        $this->actingAsStaff(Role::Doctor);

        $this->get(route('panel.reports.index', [], false))->assertForbidden();
        $this->get(route('panel.reports.show', ['report' => 'appointments'], false))->assertForbidden();
        $this->get(route('panel.reports.export', ['report' => 'appointments', 'format' => 'csv'], false))->assertForbidden();
    }

    public function test_an_empty_scope_offers_no_reports_and_allows_none(): void
    {
        $none = ReportScope::none();

        $this->assertTrue($none->deniesAll());
        $this->assertSame([], $none->visibleReports());

        foreach (ReportKind::cases() as $kind) {
            $this->assertFalse($none->allows($kind), "{$kind->value} must not be allowed by an empty scope");
        }
    }

    /**
     * The dashboard's payload contains the clinic's takings only for a scope that may see them, so the cache
     * row has to be keyed by that capability too — otherwise the first admin to open it warms a payload with
     * the money in it and the next viewer is handed that same row.
     */
    public function test_the_dashboard_cache_row_is_not_shared_across_scopes_of_different_rights(): void
    {
        $this->seedReportFixture();
        $this->actingAsStaff(Role::HospitalAdmin);

        $filters = ReportFilters::forDays('2026-03-10', '2026-03-10');
        $withMoney = new ReportScope(financial: true, clinical: true, allDoctors: true, canExport: true);
        $withoutMoney = new ReportScope(financial: false, clinical: true, allDoctors: true, canExport: true);

        $this->assertNotSame($withMoney->cacheVariant(), $withoutMoney->cacheVariant());

        $data = app(ReportData::class);
        $admin = $data->for(ReportKind::Dashboard, $filters, $withMoney);
        $this->assertArrayHasKey('money', $admin['data']);
        $this->assertNotNull($admin['data']['money']);

        // A warm cache must not hand the money-blind scope the admin's payload.
        $blind = $data->for(ReportKind::Dashboard, $filters, $withoutMoney);
        $this->assertFalse($blind['cached'], 'a different capability set is a different cache row');
        $this->assertNull($blind['data']['money'], 'a scope that may not see money must never be served it');
    }
}
