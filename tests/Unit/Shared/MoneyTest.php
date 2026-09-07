<?php

declare(strict_types=1);

namespace Tests\Unit\Shared;

use App\Domain\Shared\Money;
use PHPUnit\Framework\TestCase;

final class MoneyTest extends TestCase
{
    public function test_formats_paisa_as_taka(): void
    {
        $this->assertSame('৳1,250.00', Money::bdt(125000)->format());
        $this->assertSame('৳0.05', Money::bdt(5)->format());
        $this->assertSame('-৳10.50', Money::bdt(-1050)->format());
    }

    public function test_arithmetic_and_json(): void
    {
        $sum = Money::bdt(100)->add(Money::fromTaka('2.5'));

        $this->assertSame(350, $sum->paisa);
        $this->assertSame(['paisa' => 350, 'formatted' => '৳3.50'], $sum->jsonSerialize());
        $this->assertTrue(Money::bdt(350)->subtract($sum)->isZero());
    }
}
