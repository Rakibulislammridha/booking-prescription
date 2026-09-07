<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportScope;
use App\Domain\Reports\Enums\ReportKind;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class ReportScopeTest extends TestCase
{
    private function filters(): ReportFilters
    {
        return new ReportFilters(from: CarbonImmutable::parse('2026-03-01'), to: CarbonImmutable::parse('2026-03-31'));
    }

    public function test_an_accountant_sees_money_but_no_clinical_detail(): void
    {
        $accountant = new ReportScope(financial: true, clinical: false, allDoctors: true, canExport: true);

        $this->assertTrue($accountant->allows(ReportKind::Revenue));
        $this->assertFalse($accountant->allows(ReportKind::Clinical));
        $this->assertTrue($accountant->allows(ReportKind::Appointments));
        $this->assertNotContains(ReportKind::Clinical, $accountant->visibleReports());
    }

    public function test_a_doctor_sees_clinical_numbers_but_not_the_clinics_revenue(): void
    {
        $doctor = new ReportScope(financial: false, clinical: true, allDoctors: false, canExport: true, doctorId: 42);

        $this->assertFalse($doctor->allows(ReportKind::Revenue));
        $this->assertTrue($doctor->allows(ReportKind::Clinical));
        $this->assertNotContains(ReportKind::Revenue, $doctor->visibleReports());
    }

    public function test_the_scope_overwrites_the_doctor_filter_rather_than_validating_it(): void
    {
        $doctor = new ReportScope(financial: false, clinical: true, allDoctors: false, canExport: true, doctorId: 42);

        // Someone hand-edits the query string to another doctor.
        $this->assertSame(42, $doctor->apply($this->filters()->withDoctor(99))->doctorId);

        // Whereas an all-doctors scope leaves the filter exactly as the user chose it.
        $admin = new ReportScope(financial: true, clinical: true, allDoctors: true, canExport: true);
        $this->assertSame(99, $admin->apply($this->filters()->withDoctor(99))->doctorId);
        $this->assertNull($admin->apply($this->filters())->doctorId);
    }

    public function test_a_user_without_reports_sees_nothing(): void
    {
        $none = ReportScope::none();

        $this->assertFalse($none->financial);
        $this->assertFalse($none->clinical);
        $this->assertFalse($none->canExport);
        // A scope with neither all-doctors nor a doctor row filters on `doctor_id = null`: empty by construction.
        $this->assertNull($none->apply($this->filters()->withDoctor(99))->doctorId);
    }
}
