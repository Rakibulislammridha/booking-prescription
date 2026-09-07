<?php

declare(strict_types=1);

namespace App\Domain\Patients\Data;

use Carbon\CarbonImmutable;

/** Keyset cursor `(occurred_at, kind, id)` for the timeline (PRESCRIPTION.md §8); opaque base64 on the wire. */
final readonly class TimelineCursor
{
    public function __construct(
        public CarbonImmutable $occurredAt,
        public string $kind,
        public int $id,
    ) {}

    public function encode(): string
    {
        $json = json_encode(['t' => $this->occurredAt->getPreciseTimestamp(6), 'k' => $this->kind, 'i' => $this->id], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    public static function decode(?string $cursor): ?self
    {
        if ($cursor === null || $cursor === '') {
            return null;
        }

        $json = base64_decode(strtr($cursor, '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);

        if (! is_array($data) || ! isset($data['t'], $data['k'], $data['i'])) {
            return null;
        }

        return new self(CarbonImmutable::createFromTimestampMs((int) $data['t'] / 1000)->utc(), (string) $data['k'], (int) $data['i']);
    }

    /** True when $entry is strictly older than this cursor in (occurred_at DESC, kind DESC, id DESC) order. */
    public function isAfter(TimelineEntry $entry): bool
    {
        $t = $this->occurredAt->getPreciseTimestamp(6) <=> $entry->occurredAt->getPreciseTimestamp(6);

        if ($t !== 0) {
            return $t > 0;
        }

        $k = strcmp($this->kind, $entry->kind);

        return $k !== 0 ? $k > 0 : $this->id > $entry->id;
    }
}
