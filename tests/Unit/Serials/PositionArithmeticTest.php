<?php

declare(strict_types=1);

namespace Tests\Unit\Serials;

use App\Domain\Serials\Exceptions\NeedsRenormalisation;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialEventWriter;
use PHPUnit\Framework\TestCase;

/** SERIAL_ENGINE §7.1 midpoint arithmetic (pure; the writer is never touched by between()). */
final class PositionArithmeticTest extends TestCase
{
    private function service(): PositionService
    {
        return new PositionService(new SerialEventWriter);
    }

    public function test_midpoint_head_tail_and_empty(): void
    {
        $s = $this->service();
        $this->assertSame(1_000_000, $s->between(null, null));
        $this->assertSame(1_500_000, $s->between(1_000_000, 2_000_000));
        $this->assertSame(3_000_000, $s->between(2_000_000, null), 'tail = max + GAP');
        $this->assertSame(1_000_000, $s->between(null, 2_000_000), 'head = min - GAP');
        $this->assertSame(500_000, $s->between(null, 1_000_000), 'head below one gap halves');
        $this->assertSame(1_000_001, $s->between(1_000_000, 1_000_002));
    }

    public function test_no_room_signals_renormalisation(): void
    {
        $s = $this->service();
        $this->expectException(NeedsRenormalisation::class);
        $s->between(1_000_000, 1_000_001);
    }

    public function test_head_with_no_room_signals_renormalisation(): void
    {
        $s = $this->service();
        $this->expectException(NeedsRenormalisation::class);
        $s->between(null, 1);
    }

    public function test_twenty_consecutive_midpoints_fit_in_one_gap(): void
    {
        $s = $this->service();
        $a = 1_000_000;
        $b = 2_000_000;
        for ($i = 0; $i < 19; $i++) {
            $b = $s->between($a, $b);
        }
        $this->assertGreaterThan($a, $b);
        $this->expectException(NeedsRenormalisation::class);
        $s->between($a, $s->between($a, $b));
    }
}
