<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Queries\PatientMixQuery;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Models\Tenant\Appointment;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * Five distinct patients are seen in March (P1, P2, P4, P5, P6). Only P2 has an earlier visit — 15 January —
 * so P2 is the single RETURNING patient and the other four are new to the clinic. P1 is seen by two doctors and
 * must still count once at clinic level.
 */
final class PatientMixTest extends TestCase
{
    use ReportFixture;

    private PatientMixQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->seedReportFixture();
        $this->query = app(PatientMixQuery::class);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function march(): ReportFilters
    {
        return ReportFilters::forDays('2026-03-01', '2026-03-10');
    }

    public function test_a_patient_seen_twice_and_by_two_doctors_counts_once_at_clinic_level(): void
    {
        $totals = $this->query->newVsReturning($this->march());

        $this->assertSame(5, $totals['patients']);
        $this->assertSame(4, $totals['new']);
        $this->assertSame(1, $totals['returning'], 'only P2 was seen before March');
        $this->assertSame(80.0, $totals['new_rate']);
    }

    public function test_new_means_new_to_the_clinic_not_new_to_the_doctor(): void
    {
        // P1 was seen by Dr Rahman on 1 March and by Dr Sultana on 2 March. Under Sultana's filter P1 is still
        // NEW, because their first ever visit to the clinic is inside the range; had it been in January they
        // would read as returning even though Sultana had never seen them.
        $sultana = $this->march()->withDoctor($this->sultana->id);
        $totals = $this->query->newVsReturning($sultana);

        $this->assertSame(2, $totals['patients']);   // P4 and P1
        $this->assertSame(2, $totals['new']);
        $this->assertSame(0, $totals['returning']);

        // And under Rahman, P2 is returning purely because of the January visit.
        $rahman = $this->query->newVsReturning($this->march()->withDoctor($this->rahman->id));
        $this->assertSame(4, $rahman['patients']);
        $this->assertSame(3, $rahman['new']);
        $this->assertSame(1, $rahman['returning']);
    }

    public function test_the_per_doctor_rows_deliberately_sum_to_more_than_the_clinic_total(): void
    {
        $rows = $this->rows($this->query->byDoctor($this->march()));

        $this->assertSame(4, $rows->firstWhere('doctor_id', $this->rahman->id)['patients']);
        $this->assertSame(2, $rows->firstWhere('doctor_id', $this->sultana->id)['patients']);
        // 4 + 2 = 6 against a clinic total of 5: P1 appears under both, which the page footnotes.
        $this->assertSame(6, $rows->sum('patients'));
        $this->assertSame(5, $this->query->newVsReturning($this->march())['patients']);
    }

    public function test_follow_up_compliance_counts_only_follow_ups_already_due(): void
    {
        $follow = $this->query->followUpCompliance($this->march());

        // Four visits advised a follow-up; three of those dates have passed by 10 March.
        $this->assertSame(4, $follow['advised']);
        $this->assertSame(3, $follow['due']);
        // Real bookings exist for V1 (completed), V2 (confirmed) and V4 (confirmed, not yet due).
        $this->assertSame(3, $follow['booked']);
        $this->assertSame(2, $follow['booked_due']);
        // Only V1's follow-up was actually attended.
        $this->assertSame(1, $follow['kept']);
        $this->assertSame(1, $follow['kept_due']);
        $this->assertSame(66.7, $follow['booking_rate']);      // 2 of 3 due
        $this->assertSame(33.3, $follow['compliance_rate']);   // 1 of 3 due
        $this->assertSame('2026-03-10', $follow['as_of']);
    }

    public function test_the_systems_own_draft_appointment_does_not_count_as_booked(): void
    {
        // V3 has only the draft row Booking's listener creates automatically. It is due and unbooked.
        $follow = $this->query->followUpCompliance($this->march());
        $this->assertSame(2, $follow['booked_due'], 'the draft placeholder is not a booking a human made');

        // Promote that draft to a real booking and the funnel moves by exactly one.
        Appointment::query()
            ->where('follow_up_of_visit_id', $this->visits['V3']->id)
            ->firstOrFail()
            ->forceFill(['status' => 'confirmed', 'session_instance_id' => $this->sessionOn($this->rahman, $this->main, '2026-03-07', '09:00', '13:00', SessionStatus::Scheduled)->id])
            ->save();

        $this->assertSame(3, $this->query->followUpCompliance($this->march())['booked_due']);
        $this->assertSame(100.0, $this->query->followUpCompliance($this->march())['booking_rate']);
    }

    public function test_by_period_counts_a_patient_on_each_day_they_were_seen(): void
    {
        $rows = $this->rows($this->query->byPeriod($this->march()))->keyBy('period');

        // 1 March: P1, P2, P5, P6 — of those only P2 is returning to the clinic.
        $this->assertSame(4, $rows['2026-03-01']['patients']);
        $this->assertSame(3, $rows['2026-03-01']['new']);
        $this->assertSame(1, $rows['2026-03-01']['returning']);
        $this->assertSame(75.0, $rows['2026-03-01']['new_rate']);

        // 2 March: P4 AND P1 again. P1 counts on both days — the trend is "patients seen per day", not a
        // partition of the range, and the page footnotes that its days sum to more than the distinct total.
        $this->assertSame(2, $rows['2026-03-02']['patients']);
        $this->assertSame(2, $rows['2026-03-02']['new']);
        $this->assertSame(0, $rows['2026-03-02']['returning']);

        // 6 day-patients against 5 distinct patients in the range.
        $this->assertSame(6, $this->rows($this->query->byPeriod($this->march()))->sum('patients'));
        $this->assertSame(5, $this->query->newVsReturning($this->march())['patients']);
    }

    public function test_the_per_doctor_rows_carry_their_own_new_rate(): void
    {
        $rows = $this->rows($this->query->byDoctor($this->march()))->keyBy('doctor_id');

        $this->assertSame(75.0, $rows[$this->rahman->id]['new_rate']);    // 3 new of 4
        $this->assertSame(100.0, $rows[$this->sultana->id]['new_rate']);  // 2 new of 2
    }
}
