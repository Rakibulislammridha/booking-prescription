<?php

declare(strict_types=1);

namespace Tests\Unit\Billing;

use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Services\InvoiceCalculator;
use App\Domain\Billing\Services\Paisa;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The arithmetic that decides what a patient pays. Pure integers: if any of this ever routes through a float,
 * one of these cases breaks.
 */
final class MoneyArithmeticTest extends TestCase
{
    /** @return array<string, array{0: string|int|float, 1: int}> */
    public static function decimals(): array
    {
        return [
            'whole taka' => ['500', 50000],
            'two decimals' => ['1250.75', 125075],
            'one decimal' => ['12.5', 1250],
            'zero' => ['0.00', 0],
            'leading zeros' => ['007.05', 705],
            'the classic float trap 0.1 + 0.2' => ['0.30', 30],
            'negative' => ['-12.34', -1234],
            'big' => ['99999999.99', 9999999999],
            'int input' => [500, 50000],
            'more decimals than scale are truncated, never rounded up' => ['1.999', 199],
            'empty' => ['', 0],
        ];
    }

    #[DataProvider('decimals')]
    public function test_decimal_strings_become_exact_paisa(string|int|float $value, int $expected): void
    {
        $this->assertSame($expected, Paisa::fromDecimal($value));
    }

    public function test_the_float_trap_never_appears(): void
    {
        // 0.1 + 0.2 === 0.30000000000000004 in binary floating point; via decimal strings it is exactly 30 paisa.
        $this->assertSame(30, Paisa::fromDecimal('0.10') + Paisa::fromDecimal('0.20'));
        $this->assertSame(Paisa::fromDecimal('0.30'), Paisa::fromDecimal('0.10') + Paisa::fromDecimal('0.20'));
    }

    /** @return array<string, array{0: int, 1: string, 2: int}> */
    public static function percentages(): array
    {
        return [
            '15% of 500.00' => [50000, '15', 7500],
            '7.5% of 500.00' => [50000, '7.5', 3750],
            '0% of anything' => [50000, '0', 0],
            '100% of 500.00' => [50000, '100', 50000],
            'a tie rounds up, never down' => [1, '50', 1],           // 0.5 paisa → 1
            'below a tie rounds down' => [1, '49', 0],
            '33.33% of 1.00' => [100, '33.33', 33],
            'nothing to take a percentage of' => [0, '15', 0],
            'negative base is clamped to nothing' => [-100, '15', 0],
        ];
    }

    #[DataProvider('percentages')]
    public function test_percentages_are_basis_points_rounded_half_up(int $amount, string $percent, int $expected): void
    {
        $this->assertSame($expected, Paisa::applyBasisPoints($amount, Paisa::percentToBasisPoints($percent)));
    }

    public function test_paisa_round_trips_through_its_decimal_form(): void
    {
        foreach ([0, 1, 99, 100, 12345, 9999999999] as $paisa) {
            $this->assertSame($paisa, Paisa::fromDecimal(Paisa::toDecimal($paisa)));
        }

        $this->assertSame('-12.34', Paisa::toDecimal(-1234));
        $this->assertSame('0.05', Paisa::toDecimal(5));
    }

    public function test_totals_never_go_negative_however_much_is_discounted(): void
    {
        $calculator = new InvoiceCalculator;

        // Discounts bigger than the bill are clamped, so a "free" visit is ৳0 and never a credit to be refunded.
        $totals = $calculator->totals([50000], discountPaisa: 90000, couponDiscountPaisa: 30000, vatBasisPoints: 0);

        $this->assertSame(50000, $totals->subtotalPaisa);
        $this->assertSame(50000, $totals->discountPaisa);
        $this->assertSame(0, $totals->couponDiscountPaisa, 'the coupon has nothing left to reduce');
        $this->assertSame(0, $totals->totalPaisa);
    }

    public function test_vat_is_charged_on_the_discounted_base_not_the_list_price(): void
    {
        $calculator = new InvoiceCalculator;
        // 800.00 − 100.00 = 700.00 base; 7.5% VAT = 52.50; total 752.50.
        $totals = $calculator->totals([80000], discountPaisa: 10000, couponDiscountPaisa: 0, vatBasisPoints: Paisa::percentToBasisPoints('7.5'));

        $this->assertSame(70000, $totals->subtotalPaisa - $totals->discountPaisa);
        $this->assertSame(5250, $totals->vatPaisa);
        $this->assertSame(75250, $totals->totalPaisa);
    }

    public function test_a_fixed_discount_is_taka_as_entered_and_a_percentage_is_a_percentage(): void
    {
        $calculator = new InvoiceCalculator;

        $this->assertSame(10000, $calculator->discountAmount(DiscountType::Fixed, '100.00', 80000));
        $this->assertSame(8000, $calculator->discountAmount(DiscountType::Percentage, '10', 80000));
        // Both are capped by what is left to reduce.
        $this->assertSame(80000, $calculator->discountAmount(DiscountType::Fixed, '5000.00', 80000));
    }

    public function test_line_totals_satisfy_the_database_check(): void
    {
        $calculator = new InvoiceCalculator;

        $this->assertSame(240000, $calculator->lineTotal(3, 80000));
        $this->assertSame(80000, $calculator->lineTotal(0, 80000), 'quantity is at least 1 (quantity > 0 is a CHECK)');
        $this->assertSame(0, $calculator->lineTotal(2, -5), 'a negative price is impossible, not negative money');
    }

    public function test_a_sum_of_many_small_percentage_lines_loses_no_paisa(): void
    {
        // Ten lines of 33 paisa at 33.33%: each rounds independently, and the total is the sum of the parts —
        // there is no separate "total percentage" that could disagree with the lines.
        $each = Paisa::applyBasisPoints(33, Paisa::percentToBasisPoints('33.33'));
        $this->assertSame(11, $each);
        $this->assertSame(110, $each * 10);
    }
}
