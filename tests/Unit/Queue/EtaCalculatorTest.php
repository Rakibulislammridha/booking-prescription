<?php

declare(strict_types=1);

namespace Tests\Unit\Queue;

use App\Domain\Queue\Services\EtaCalculator;
use App\Domain\Serials\Services\ConsultAverage;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * The ETA arithmetic of SERIAL_ENGINE §13 / REALTIME.md §4.1 (pure part): the running average itself is the Serials
 * module's EMA (ConsultAverage), which this class delegates to; what is owned here is the per-serial offset and the
 * presentation rounding.
 */
final class EtaCalculatorTest extends TestCase
{
    public function test_it_reuses_the_serials_running_average_constants(): void
    {
        $this->assertSame(ConsultAverage::ALPHA, EtaCalculator::ALPHA);
        $this->assertSame(ConsultAverage::MIN_SAMPLES, EtaCalculator::MIN_SAMPLES);
        $this->assertSame(ConsultAverage::CLAMP, EtaCalculator::CLAMP);
        $this->assertSame(0.8, EtaCalculator::DEFAULT_SHOW_RATE);
    }

    public function test_offset_is_the_rest_of_the_current_consultation_plus_one_average_per_serial_ahead(): void
    {
        // 300 s average, 120 s left of the current patient, 3 checked in ahead, no bookings ahead
        $this->assertSame(120 + 3 * 300, EtaCalculator::offsetSeconds(300, 120, 3, 0, 0.8));

        // nobody ahead: only the rest of the current consultation
        $this->assertSame(120, EtaCalculator::offsetSeconds(300, 120, 0, 0, 0.8));

        // nothing in the chamber and nobody ahead: the estimate is `base` itself
        $this->assertSame(0, EtaCalculator::offsetSeconds(300, 0, 0, 0, 0.8));
    }

    public function test_booked_but_not_arrived_serials_are_weighted_by_the_expected_show_rate(): void
    {
        // 10 booked ahead at 0.8 → 8 counted; at 1.0 → 10; at 0.0 → none
        $this->assertSame(8 * 300, EtaCalculator::offsetSeconds(300, 0, 0, 10, 0.8));
        $this->assertSame(10 * 300, EtaCalculator::offsetSeconds(300, 0, 0, 10, 1.0));
        $this->assertSame(0, EtaCalculator::offsetSeconds(300, 0, 0, 10, 0.0));

        // rounding is to the nearest whole patient: 5 × 0.8 = 4
        $this->assertSame(4 * 300, EtaCalculator::offsetSeconds(300, 0, 0, 5, 0.8));
    }

    public function test_a_negative_remaining_never_pulls_the_estimate_backwards_and_the_average_is_at_least_one_second(): void
    {
        $this->assertSame(2 * 300, EtaCalculator::offsetSeconds(300, -600, 2, 0, 0.8));
        $this->assertSame(2, EtaCalculator::offsetSeconds(0, 0, 2, 0, 0.8));
    }

    public function test_displayed_rounds_up_to_five_minutes_and_never_shows_a_time_in_the_past(): void
    {
        $now = CarbonImmutable::parse('2026-09-07T10:03:10+06:00');

        $this->assertSame('10:05', EtaCalculator::displayed($now->addMinute(), $now)->setTimezone($now->getTimezone())->format('H:i'));
        $this->assertSame('10:10', EtaCalculator::displayed($now->addMinutes(6), $now)->setTimezone($now->getTimezone())->format('H:i'));

        // an estimate already in the past is floored at now + 1 min, then rounded up
        $inThePast = EtaCalculator::displayed($now->subHour(), $now)->setTimezone($now->getTimezone());
        $this->assertTrue($inThePast->greaterThan($now));
        $this->assertSame(0, $inThePast->getTimestamp() % 300, 'rounded to a 5-minute boundary');
    }
}
