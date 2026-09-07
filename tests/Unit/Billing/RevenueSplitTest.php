<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Domain\Billing\Enums\RevenueShareType;
use App\Domain\Billing\Services\Paisa;
use PHPUnit\Framework\TestCase;

/**
 * The doctor/clinic split is exhaustive by construction: `clinic = line_total − doctor`. Whatever the percentage
 * and however it rounds, the two halves add back up to the line, which is what the
 * `doctor_share_paisa + clinic_share_paisa <= line_total_paisa` CHECK demands.
 */
final class RevenueSplitTest extends TestCase
{
    /**
     * Mirrors RevenueShareResolver::split() without touching the database.
     *
     * @return array{0: int, 1: int} doctor share, clinic share
     */
    private function split(int $lineTotal, RevenueShareType $type, string $value): array
    {
        $doctor = $type === RevenueShareType::Percentage
            ? Paisa::applyBasisPoints($lineTotal, Paisa::percentToBasisPoints($value))
            : Paisa::fromDecimal($value);

        $doctor = Paisa::clamp($doctor, $lineTotal);

        return [$doctor, $lineTotal - $doctor];
    }

    public function test_a_percentage_split_is_exhaustive_at_every_odd_amount(): void
    {
        foreach (['60', '33.33', '50', '66.67', '0.01', '99.99'] as $percent) {
            foreach ([1, 3, 7, 99, 101, 12345, 80000, 999999] as $lineTotal) {
                [$doctor, $clinic] = $this->split($lineTotal, RevenueShareType::Percentage, $percent);

                $this->assertSame($lineTotal, $doctor + $clinic, "split of {$lineTotal} at {$percent}% lost a paisa");
                $this->assertGreaterThanOrEqual(0, $doctor);
                $this->assertGreaterThanOrEqual(0, $clinic);
            }
        }
    }

    public function test_a_flat_share_larger_than_the_line_cannot_overdraw_the_clinic(): void
    {
        [$doctor, $clinic] = $this->split(50000, RevenueShareType::Fixed, '900.00');

        $this->assertSame(50000, $doctor, 'clamped to the line total');
        $this->assertSame(0, $clinic);
    }

    public function test_a_free_line_splits_to_nothing(): void
    {
        [$doctor, $clinic] = $this->split(0, RevenueShareType::Percentage, '60');

        $this->assertSame(0, $doctor);
        $this->assertSame(0, $clinic);
    }
}
