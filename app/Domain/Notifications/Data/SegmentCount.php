<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

/** The result of SegmentCounter: what the gateway will bill for this body. */
final readonly class SegmentCount
{
    public function __construct(
        public string $encoding,          // 'GSM-7' | 'UCS-2'
        public int $units,                // septets (GSM-7) or UTF-16 code units (UCS-2)
        public int $segments,
        public int $remaining,            // units left in the last segment
        public int $perSegment,
    ) {}

    public function isUnicode(): bool
    {
        return $this->encoding === 'UCS-2';
    }

    /** @return array<string, mixed> the shape the panel's live counter consumes */
    public function toArray(): array
    {
        return [
            'encoding' => $this->encoding,
            'units' => $this->units,
            'segments' => $this->segments,
            'remaining' => $this->remaining,
            'per_segment' => $this->perSegment,
        ];
    }
}
