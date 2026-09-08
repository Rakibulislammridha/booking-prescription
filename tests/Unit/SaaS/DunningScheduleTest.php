<?php

declare(strict_types=1);

namespace Tests\Unit\SaaS;

use App\Domain\SaaS\Support\DunningSchedule;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/** The ladder, as arithmetic: the sweep can run at any hour and must still emit each step exactly once. */
final class DunningScheduleTest extends TestCase
{
    public function test_the_step_due_at_a_moment_is_the_highest_threshold_that_has_passed(): void
    {
        $due = CarbonImmutable::parse('2026-04-01 09:00:00');

        $this->assertSame(0, DunningSchedule::dueStep($due, $due->subSecond()));
        $this->assertSame(1, DunningSchedule::dueStep($due, $due));
        $this->assertSame(1, DunningSchedule::dueStep($due, $due->addDays(2)->addHours(23)));
        $this->assertSame(2, DunningSchedule::dueStep($due, $due->addDays(3)));
        $this->assertSame(2, DunningSchedule::dueStep($due, $due->addDays(6)));
        $this->assertSame(3, DunningSchedule::dueStep($due, $due->addDays(7)));
        $this->assertSame(3, DunningSchedule::dueStep($due, $due->addDays(40)), 'the ladder has a top; it does not keep climbing');
    }

    public function test_the_grace_deadline_is_after_the_last_reminder(): void
    {
        $due = CarbonImmutable::parse('2026-04-01 09:00:00');
        $last = $due->addDays(DunningSchedule::STEPS[count(DunningSchedule::STEPS) - 1]);

        $this->assertTrue(DunningSchedule::graceDeadline($due)->greaterThan($last), 'a clinic must be warned before it is cut off');
        $this->assertSame('2026-04-11 09:00:00', DunningSchedule::graceDeadline($due)->format('Y-m-d H:i:s'));
    }

    public function test_only_the_last_step_is_the_final_notice(): void
    {
        $this->assertSame(3, DunningSchedule::steps());
        $this->assertFalse(DunningSchedule::isFinalNotice(1));
        $this->assertFalse(DunningSchedule::isFinalNotice(2));
        $this->assertTrue(DunningSchedule::isFinalNotice(3));
    }

    public function test_the_invoice_is_payable_before_it_is_overdue(): void
    {
        $this->assertGreaterThan(0, DunningSchedule::NET_DAYS);
        $this->assertGreaterThan(DunningSchedule::STEPS[count(DunningSchedule::STEPS) - 1], DunningSchedule::GRACE_DAYS);
    }
}
