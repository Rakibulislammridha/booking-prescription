<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\CatalogReferenceCheck;
use PHPUnit\Framework\TestCase;

final class CatalogReferenceCheckTest extends TestCase
{
    public function test_valid_triple_passes_and_discontinued_rows_warn(): void
    {
        $check = new CatalogReferenceCheck(FakeCatalog::make());
        $this->assertSame([], $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d', brandId: 88, strengthId: 1234)])));

        $inactiveBrand = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d', brandId: 90)]));
        $this->assertSame('catalog.discontinued', $inactiveBrand[0]->code);
        $this->assertSame('warning', $inactiveBrand[0]->severity->value);
        $this->assertTrue($inactiveBrand[0]->overridable);

        $inactiveStrength = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d', brandId: 88, strengthId: 1236)]));
        $this->assertSame('catalog:discontinued:17:strength', $inactiveStrength[0]->fingerprint);
    }

    public function test_missing_or_mismatched_references_are_non_overridable_criticals(): void
    {
        $check = new CatalogReferenceCheck(FakeCatalog::make());

        $missingGeneric = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 999, '1 tds 5d')]));
        $this->assertSame('catalog.ref_missing', $missingGeneric[0]->code);
        $this->assertFalse($missingGeneric[0]->overridable);
        $this->assertSame('catalog:ref_missing:999:generic999', $missingGeneric[0]->fingerprint);
        $this->assertTrue($missingGeneric[0]->blocksIssue());

        $wrongBrand = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d', brandId: 91)]));   // Warf belongs to warfarin
        $this->assertSame('catalog:ref_missing:17:brand91', $wrongBrand[0]->fingerprint);

        $wrongStrength = $check->run(FakeCatalog::context([FakeCatalog::tablet('a', 17, '1 tds 5d', brandId: 88, strengthId: 1235)]));   // 1235 belongs to brand 89
        $this->assertSame('catalog:ref_missing:17:strength1235', $wrongStrength[0]->fingerprint);
    }
}
