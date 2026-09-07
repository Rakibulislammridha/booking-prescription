<?php

declare(strict_types=1);

namespace Tests\Unit\Serials;

use App\Domain\Serials\Services\ConsultAverage;
use PHPUnit\Framework\TestCase;

/** SERIAL_ENGINE §13 / §18.7 EMA maths (pure). */
final class ConsultAverageTest extends TestCase
{
    public function test_seed_is_kept_until_samples_arrive_then_ema_moves_it(): void
    {
        $this->assertSame(360, ConsultAverage::next(360, null));
        $this->assertSame((int) round(0.25 * 300 + 0.75 * 360), ConsultAverage::next(360, 300));
        $avg = 360;
        foreach ([300, 300, 300, 300, 300, 300, 300] as $sample) {
            $avg = ConsultAverage::next($avg, $sample);
        }
        $this->assertLessThan(315, $avg, '≈ 7-sample window converges towards the samples');
        $this->assertGreaterThan(300, $avg);
    }

    public function test_outlier_samples_are_discarded(): void
    {
        $this->assertSame(360, ConsultAverage::next(360, 10), 'below 30 s: the doctor pressed complete by mistake');
        $this->assertSame(360, ConsultAverage::next(360, 5000), 'above 30 min: forgot to press complete');
        $this->assertTrue(ConsultAverage::accepts(30));
        $this->assertTrue(ConsultAverage::accepts(1800));
        $this->assertFalse(ConsultAverage::accepts(29));
        $this->assertFalse(ConsultAverage::accepts(1801));
        $this->assertFalse(ConsultAverage::accepts(null));
    }

    public function test_confidence_is_low_until_three_samples(): void
    {
        $this->assertSame('low', ConsultAverage::confidence(0));
        $this->assertSame('low', ConsultAverage::confidence(2));
        $this->assertSame('normal', ConsultAverage::confidence(3));
    }
}
