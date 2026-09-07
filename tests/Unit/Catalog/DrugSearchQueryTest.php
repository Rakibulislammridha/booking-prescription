<?php

declare(strict_types=1);

namespace Tests\Unit\Catalog;

use App\Domain\Catalog\Data\DrugSearchQuery;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('catalog')]
final class DrugSearchQueryTest extends TestCase
{
    public function test_trailing_number_becomes_strength_filter(): void
    {
        $this->assertSame(['nap', 500.0], DrugSearchQuery::splitTrailingNumber('nap 500'));
        $this->assertSame(['napa', null], DrugSearchQuery::splitTrailingNumber(' napa '));
        $this->assertSame(['500', null], DrugSearchQuery::splitTrailingNumber('500'));
        $this->assertSame(['cef 3', 200.0], DrugSearchQuery::splitTrailingNumber('cef 3 200'));
    }
}
