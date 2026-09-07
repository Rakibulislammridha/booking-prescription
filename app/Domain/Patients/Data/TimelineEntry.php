<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use Carbon\CarbonImmutable;

/**
 * One row of the patient timeline. `ref` is the public id of the underlying row when it has one, else its bigint
 * id as a string (rows without public_id never appear in URLs — CONVENTIONS §5).
 */
final readonly class TimelineEntry
{
    /** @param  array<string, mixed>  $meta */
    public function __construct(
        public string $kind,
        public int $id,
        public CarbonImmutable $occurredAt,
        public string $title,
        public ?string $subtitle = null,
        public ?string $ref = null,
        public array $meta = [],
    ) {}

    public function cursor(): TimelineCursor
    {
        return new TimelineCursor($this->occurredAt, $this->kind, $this->id);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'id' => $this->id,
            'occurred_at' => $this->occurredAt->toIso8601ZuluString(),
            'title' => $this->title,
            'subtitle' => $this->subtitle,
            'ref' => $this->ref,
            'meta' => $this->meta,
            'cursor' => $this->cursor()->encode(),
        ];
    }
}
