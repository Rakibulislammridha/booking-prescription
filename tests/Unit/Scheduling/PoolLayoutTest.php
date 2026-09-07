<?php

declare(strict_types=1);

namespace Tests\Unit\Scheduling;

use App\Domain\Serials\Services\PoolLayout;
use PHPUnit\Framework\TestCase;

/** SERIAL_ENGINE §3.1 layout arithmetic (pure). */
final class PoolLayoutTest extends TestCase
{
    public function test_counter_online_buffer_layout(): void
    {
        $r = PoolLayout::ranges(10, 10, 5);
        $this->assertSame(['buffer', 'counter', 'online'], array_keys($r), 'lock order: buffer, counter, online');
        $this->assertSame(['range_start' => 1, 'range_end' => 10, 'next_number' => 1], $r['counter']);
        $this->assertSame(['range_start' => 11, 'range_end' => 20, 'next_number' => 11], $r['online']);
        $this->assertSame(['range_start' => 21, 'range_end' => 25, 'next_number' => 21], $r['buffer']);
    }

    public function test_zero_quotas_are_empty_ranges(): void
    {
        $r = PoolLayout::ranges(0, 0, 0);
        foreach ($r as $range) {
            $this->assertSame($range['range_start'] - 1, $range['range_end']);
            $this->assertSame($range['range_start'], $range['next_number']);
        }
        $r = PoolLayout::ranges(5, 0, 3);
        $this->assertSame(['range_start' => 6, 'range_end' => 5, 'next_number' => 6], $r['online']);
        $this->assertSame(['range_start' => 6, 'range_end' => 8, 'next_number' => 6], $r['buffer']);
    }
}
