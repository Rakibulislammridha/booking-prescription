<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

/** One page of the merged timeline; `nextCursor` is null when this page is the end. */
final readonly class TimelinePage
{
    /** @param  array<int, TimelineEntry>  $entries */
    public function __construct(
        public array $entries,
        public ?string $nextCursor,
    ) {}

    /** @return array{data: array<int, array<string, mixed>>, meta: array{next_cursor: string|null}} */
    public function toArray(): array
    {
        return [
            'data' => array_map(fn (TimelineEntry $e) => $e->toArray(), $this->entries),
            'meta' => ['next_cursor' => $this->nextCursor],
        ];
    }
}
