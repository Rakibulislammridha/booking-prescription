<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Support;

use App\Domain\Prescription\Support\Temperature;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one server-side temperature conversion (BRIEF §5.G.2). The column stores °C (SCHEMA §3.4); people type
 * and read °F. The table here is the same one `resources/js/shared/format/__tests__/temperature.test.ts` pins for
 * the client, so the two boundaries can never disagree about what 98.6 means.
 */
final class TemperatureTest extends TestCase
{
    /** @return list<array{0: float, 1: float}> [°F, °C] */
    public static function readings(): array
    {
        return [[98.6, 37.0], [100.4, 38.0], [102.2, 39.0], [104.0, 40.0], [97.7, 36.5], [86.0, 30.0], [113.0, 45.0]];
    }

    #[DataProvider('readings')]
    public function test_it_converts_both_ways_to_one_decimal(float $f, float $c): void
    {
        $this->assertSame($c, Temperature::fToC($f));
        $this->assertSame($f, Temperature::cToF($c));
    }

    #[DataProvider('readings')]
    public function test_a_reading_survives_the_round_trip_through_the_stored_unit(float $f, float $c): void
    {
        $this->assertSame($f, Temperature::cToF(Temperature::fToC($f)));
        $this->assertSame($c, Temperature::fToC(Temperature::cToF($c)));
    }

    public function test_it_rounds_to_the_columns_precision(): void
    {
        $this->assertSame(37.1, Temperature::fToC(98.7));      // 37.06 → 37.1 (numeric(4,1))
        $this->assertSame(37.2, Temperature::fToC(99.0));      // 37.22 → 37.2
        $this->assertSame(102.9, Temperature::cToF(39.4));     // 102.92 → 102.9
        $this->assertSame(99.0, Temperature::cToF(37.2));      // 98.96 → 99.0
    }

    public function test_the_entry_bounds_are_the_stored_check_in_fahrenheit(): void
    {
        $this->assertSame(86.0, Temperature::MIN_F);
        $this->assertSame(113.0, Temperature::MAX_F);
        $this->assertSame(Temperature::MIN_C, Temperature::fToC(Temperature::MIN_F));
        $this->assertSame(Temperature::MAX_C, Temperature::fToC(Temperature::MAX_F));
    }

    public function test_it_formats_a_reading_for_a_person_in_the_print_language(): void
    {
        $this->assertSame('98.6°F', Temperature::formatF(37.0));
        $this->assertSame('100.4°F', Temperature::formatF(38.0, 'both'));
        $this->assertSame('104.0°F', Temperature::formatF(40, 'en'));           // one decimal even for a whole number
        $this->assertSame('100.8°F', Temperature::formatF('38.2'));             // an old snapshot stored the value as text
        $this->assertSame('১০০.৪°ফা', Temperature::formatF(38.0, 'bn'));
        $this->assertSame('98.6 °F', Temperature::formatF(37.0, 'en', ' '));
        $this->assertNull(Temperature::formatF(null));
        $this->assertNull(Temperature::formatF(''));
    }
}
