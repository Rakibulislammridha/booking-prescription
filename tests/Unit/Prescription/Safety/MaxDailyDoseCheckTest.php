<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\MaxDailyDoseCheck;
use PHPUnit\Framework\TestCase;

final class MaxDailyDoseCheckTest extends TestCase
{
    public function test_two_brands_of_one_generic_sum_their_daily_mg(): void
    {
        $check = new MaxDailyDoseCheck(FakeCatalog::make());
        $ctx = FakeCatalog::context([FakeCatalog::tablet('napa', 17, '2 tds 5d', brandId: 88), FakeCatalog::tablet('ace', 17, '1 tds 5d', brandId: 89)]);   // 3000 + 1500 = 4500 > 4000
        $alerts = $check->run($ctx);

        $this->assertSame('max_dose.over', $alerts[0]->code);
        $this->assertSame('critical', $alerts[0]->severity->value);
        $this->assertSame('max_dose:over:17', $alerts[0]->fingerprint);
        $this->assertEqualsCanonicalizing(['napa', 'ace'], $alerts[0]->itemKeys);
        $this->assertSame(4500.0, $alerts[0]->evidence['daily_mg']);
        $this->assertSame(4000.0, $ctx->computed['napa']['adult_max_mg_day']);
    }

    public function test_near_max_is_info_and_per_dose_is_warning(): void
    {
        $check = new MaxDailyDoseCheck(FakeCatalog::make());
        $near = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '2 qds 3d')]));   // 4000 = 100 % → over? equal to max is not over → info (≥ 80 %)
        $this->assertSame('max_dose.near', $near[0]->code);

        $perDose = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '3 od 3d')]));   // 1500 mg per dose > 1000
        $codes = array_map(fn ($x) => $x->code, $perDose);
        $this->assertContains('max_dose.per_dose', $codes);
        $this->assertSame('warning', array_values(array_filter($perDose, fn ($x) => $x->code === 'max_dose.per_dose'))[0]->severity->value);
    }

    public function test_elderly_row_applies_from_65(): void
    {
        $alerts = (new MaxDailyDoseCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '2 qds 3d')], ['ageMonths' => 70 * 12]));   // 4000 > 3000 elderly
        $this->assertSame('max_dose.over', $alerts[0]->code);
        $this->assertSame('elderly', $alerts[0]->evidence['population']);
    }

    public function test_pediatric_patients_are_left_to_the_pediatric_check(): void
    {
        $this->assertSame([], (new MaxDailyDoseCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '2 qds 3d')], ['ageMonths' => 60, 'weightKg' => 15.0])));
    }
}
