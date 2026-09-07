<?php

declare(strict_types=1);

namespace Tests\Unit\Serials;

use App\Domain\Serials\Exceptions\InvalidDisplayCode;
use App\Domain\Serials\Services\DisplayCode;
use PHPUnit\Framework\TestCase;

final class DisplayCodeTest extends TestCase
{
    public function test_format_pads_to_three_and_never_truncates(): void
    {
        $this->assertSame('A-042', DisplayCode::format('A', 42));
        $this->assertSame('A-001', DisplayCode::format('a', 1));
        $this->assertSame('B-1204', DisplayCode::format('B', 1204));
    }

    public function test_parse_round_trips_and_rejects_garbage(): void
    {
        $this->assertSame(['session_code' => 'A', 'number' => 42], DisplayCode::parse('A-042'));
        $this->assertSame(['session_code' => 'B', 'number' => 1204], DisplayCode::parse(' b-1204 '));
        $this->expectException(InvalidDisplayCode::class);
        DisplayCode::parse('42');
    }
}
