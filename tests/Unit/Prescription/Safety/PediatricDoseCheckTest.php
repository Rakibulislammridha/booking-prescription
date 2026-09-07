<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\PediatricDoseCheck;
use PHPUnit\Framework\TestCase;

final class PediatricDoseCheckTest extends TestCase
{
    public function test_not_applied_to_adults(): void
    {
        $this->assertSame([], (new PediatricDoseCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '2 tds 5d')])));
    }

    public function test_weight_missing_is_one_warning(): void
    {
        $alerts = (new PediatricDoseCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1/2 tds 5d')], ['ageMonths' => 60, 'weightKg' => null]));
        $this->assertSame('pediatric.weight_missing', $alerts[0]->code);
        $this->assertSame('warning', $alerts[0]->severity->value);
        $this->assertSame('pediatric:weight_missing', $alerts[0]->fingerprint);
    }

    public function test_mg_per_kg_thresholds_grade_info_warning_critical_and_compute_is_exposed(): void
    {
        $check = new PediatricDoseCheck(FakeCatalog::make());
        $child = ['ageMonths' => 60, 'weightKg' => 15.0];   // 60 mg/kg/day max → 900 mg/day

        $ctx = FakeCatalog::context([FakeCatalog::tablet('a', 17, '1/2 tds 5d')], $child);   // 750 mg/day = 50 mg/kg (83 %)
        $info = $check->run($ctx);
        $this->assertSame('pediatric.near_max', $info[0]->code);
        $this->assertSame('info', $info[0]->severity->value);
        $this->assertSame(50.0, $ctx->computed['a']['mg_per_kg_day']);

        $warning = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1/2 qds 5d')], $child));    // 1000 mg = 66.7 mg/kg
        $this->assertSame('pediatric.over_max', $warning[0]->code);
        $this->assertSame('warning', $warning[0]->severity->value);
        $this->assertSame('pediatric:pediatric.over_max:17:warning', $warning[0]->fingerprint);

        $critical = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 qds 5d')], $child));      // 2000 mg = 133 mg/kg (> 1.5×)
        $this->assertSame('critical', $critical[0]->severity->value);
        $this->assertSame('pediatric:pediatric.over_max:17:critical', $critical[0]->fingerprint);   // bucket change → new fingerprint
    }

    public function test_age_below_every_row_is_contraindicated_age(): void
    {
        $alerts = (new PediatricDoseCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('a', 40, '1/4 tds 3d', 200)], ['ageMonths' => 3, 'weightKg' => 5.0]));
        $this->assertSame('pediatric.contraindicated_age', $alerts[0]->code);
        $this->assertSame('critical', $alerts[0]->severity->value);
        $this->assertSame(6, $alerts[0]->evidence['min_age_months']);
    }
}
