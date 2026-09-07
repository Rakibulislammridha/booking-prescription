<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\RenalHepaticCheck;
use PHPUnit\Framework\TestCase;

final class RenalHepaticCheckTest extends TestCase
{
    public function test_renal_avoid_is_critical_and_caution_is_info(): void
    {
        $alerts = (new RenalHepaticCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('i', 40, '1 tds 3d', 400), FakeCatalog::tablet('p', 17, '1 tds 3d')], ['renalImpairment' => true]));
        $byCode = [];

        foreach ($alerts as $a) {
            $byCode[$a->code] = [$a->severity->value, $a->fingerprint, $a->evidence['advice']];
        }

        $this->assertSame(['critical', 'renal:avoid:40', 'Avoid in significant renal impairment.'], $byCode['renal.avoid']);
        $this->assertSame(['info', 'renal:caution:17', 'Increase dosing interval.'], $byCode['renal.caution']);
    }

    public function test_hepatic_adjust_dose_is_warning(): void
    {
        $alerts = (new RenalHepaticCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('p', 17, '1 tds 3d')], ['hepaticImpairment' => true]));
        $this->assertSame('hepatic.adjust_dose', $alerts[0]->code);
        $this->assertSame('warning', $alerts[0]->severity->value);
        $this->assertSame('hepatic', $alerts[0]->key);
    }

    public function test_nothing_without_the_flags(): void
    {
        $this->assertSame([], (new RenalHepaticCheck(FakeCatalog::make()))->run(FakeCatalog::context([FakeCatalog::tablet('i', 40, '1 tds 3d', 400)])));
    }
}
