<?php

declare(strict_types=1);

namespace Tests\Unit\Patients;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class TimelineCursorTest extends TestCase
{
    public function test_round_trips_through_its_opaque_encoding_with_microsecond_precision(): void
    {
        $at = CarbonImmutable::parse('2026-09-06 10:15:30.123456', 'UTC');
        $cursor = new TimelineCursor($at, 'document', 42);

        $decoded = TimelineCursor::decode($cursor->encode());

        $this->assertNotNull($decoded);
        $this->assertSame('document', $decoded->kind);
        $this->assertSame(42, $decoded->id);
        $this->assertSame($at->getPreciseTimestamp(6), $decoded->occurredAt->getPreciseTimestamp(6));
        $this->assertDoesNotMatchRegularExpression('/[+\/=]/', $cursor->encode());
    }

    public function test_garbage_decodes_to_null(): void
    {
        $this->assertNull(TimelineCursor::decode(null));
        $this->assertNull(TimelineCursor::decode(''));
        $this->assertNull(TimelineCursor::decode('not-a-cursor'));
        $this->assertNull(TimelineCursor::decode(base64_encode('{"x":1}')));
    }

    public function test_is_after_orders_by_time_then_kind_then_id_descending(): void
    {
        $t = CarbonImmutable::parse('2026-09-06 10:00:00', 'UTC');
        $cursor = new TimelineCursor($t, 'document', 10);
        $entry = fn (CarbonImmutable $at, string $kind, int $id) => new TimelineEntry($kind, $id, $at, 'x');

        $this->assertTrue($cursor->isAfter($entry($t->subSecond(), 'visit', 99)));   // older time
        $this->assertTrue($cursor->isAfter($entry($t, 'consent', 99)));             // same time, smaller kind
        $this->assertTrue($cursor->isAfter($entry($t, 'document', 9)));             // same time+kind, smaller id
        $this->assertFalse($cursor->isAfter($entry($t, 'document', 10)));           // itself
        $this->assertFalse($cursor->isAfter($entry($t, 'document', 11)));
        $this->assertFalse($cursor->isAfter($entry($t, 'visit', 1)));               // same time, greater kind
        $this->assertFalse($cursor->isAfter($entry($t->addSecond(), 'allergy', 1)));
    }
}
