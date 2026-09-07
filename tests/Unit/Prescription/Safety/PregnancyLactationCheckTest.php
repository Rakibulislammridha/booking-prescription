<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\PregnancyLactationCheck;
use PHPUnit\Framework\TestCase;

final class PregnancyLactationCheckTest extends TestCase
{
    public function test_category_x_is_critical_and_d_by_trimester_is_warning(): void
    {
        $check = new PregnancyLactationCheck(FakeCatalog::make());
        $pregnant = ['sex' => 'female', 'isPregnant' => true, 'pregnancyStatusKnown' => true];

        $x = $check->run(FakeCatalog::context([FakeCatalog::tablet('w', 203, '1 od 30d', 5)], $pregnant));
        $this->assertSame('pregnancy.category_x', $x[0]->code);
        $this->assertSame('critical', $x[0]->severity->value);
        $this->assertSame('pregnancy:pregnancy.category_x:203', $x[0]->fingerprint);

        $c = $check->run(FakeCatalog::context([FakeCatalog::tablet('i', 40, '1 tds 3d', 400)], $pregnant + ['trimester' => 1]));
        $this->assertSame([], $c);                                    // category C in trimester 1 → nothing

        $d = $check->run(FakeCatalog::context([FakeCatalog::tablet('i', 40, '1 tds 3d', 400)], $pregnant + ['trimester' => 3]));
        $this->assertSame('pregnancy.category_d', $d[0]->code);
        $this->assertSame('warning', $d[0]->severity->value);
        $this->assertSame('Third trimester: avoid.', $d[0]->evidence['notes']);
    }

    public function test_lactation_grading(): void
    {
        $alerts = (new PregnancyLactationCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('i', 40, '1 tds 3d', 400), FakeCatalog::tablet('p', 17, '1 tds 3d')], ['sex' => 'female', 'isLactating' => true, 'pregnancyStatusKnown' => true]));
        $this->assertCount(1, $alerts);
        $this->assertSame('lactation.caution', $alerts[0]->code);
        $this->assertSame('warning', $alerts[0]->severity->value);
    }

    public function test_fertile_female_with_unknown_status_gets_one_info_for_d_x_drugs(): void
    {
        $alerts = (new PregnancyLactationCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('w', 203, '1 od 30d', 5)], ['sex' => 'female', 'ageMonths' => 28 * 12]));
        $this->assertCount(1, $alerts);
        $this->assertSame('pregnancy.status_unknown', $alerts[0]->code);
        $this->assertSame('info', $alerts[0]->severity->value);
        $this->assertSame('set_status', $alerts[0]->evidence['action']);

        $male = (new PregnancyLactationCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('w', 203, '1 od 30d', 5)]));
        $this->assertSame([], $male);
    }
}
