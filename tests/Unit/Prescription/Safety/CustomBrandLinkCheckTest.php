<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Safety;

use App\Domain\Prescription\Safety\Checks\CustomBrandLinkCheck;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CustomBrandLinkCheckTest extends TestCase
{
    /** @return iterable<string, array{0: array<string, mixed>|null, 1: int, 2: string}> */
    public static function broken(): iterable
    {
        $ok = ['id' => 55, 'exists' => true, 'deleted' => false, 'is_active' => true, 'review_status' => 'pending', 'generic_id' => 17];

        yield 'row missing' => [['id' => 55, 'exists' => false], 17, 'missing'];
        yield 'soft-deleted' => [['deleted' => true] + $ok, 17, 'deleted'];
        yield 'inactive (reconcile flipped it)' => [['is_active' => false] + $ok, 17, 'inactive'];
        yield 'rejected' => [['review_status' => 'rejected'] + $ok, 17, 'rejected'];
        yield 'generic mismatch' => [$ok, 203, 'generic_mismatch'];
        yield 'generic inactive' => [['generic_id' => 70] + $ok, 70, 'generic_inactive'];
        yield 'generic vanished' => [['generic_id' => 999] + $ok, 999, 'generic_missing'];
    }

    /** @param  array<string, mixed>|null  $facts */
    #[DataProvider('broken')]
    public function test_unlinked_custom_brand_is_a_non_overridable_critical(?array $facts, int $genericId, string $reason): void
    {
        $item = FakeCatalog::tablet('c', $genericId, '1 tds 5d', customBrandId: 55, customBrand: $facts);
        $alerts = (new CustomBrandLinkCheck(FakeCatalog::make()))->run(FakeCatalog::context([$item], [], ['custom_brand:unlinked:c55' => ['reason' => 'I insist on this brand', 'by' => 1, 'at' => null]]));

        $this->assertCount(1, $alerts);
        $this->assertSame('custom_brand.unlinked', $alerts[0]->code);
        $this->assertSame('critical', $alerts[0]->severity->value);
        $this->assertFalse($alerts[0]->overridable);
        $this->assertSame('custom_brand:unlinked:c55', $alerts[0]->fingerprint);
        $this->assertSame($reason, $alerts[0]->evidence['reason']);
        $this->assertTrue($alerts[0]->blocksIssue());
    }

    public function test_linked_custom_brand_passes(): void
    {
        $item = FakeCatalog::tablet('c', 17, '1 tds 5d', customBrandId: 55, customBrand: ['id' => 55, 'exists' => true, 'deleted' => false, 'is_active' => true, 'review_status' => 'approved', 'generic_id' => 17]);
        $this->assertSame([], (new CustomBrandLinkCheck(FakeCatalog::make()))->run(FakeCatalog::context([$item])));
    }
}
