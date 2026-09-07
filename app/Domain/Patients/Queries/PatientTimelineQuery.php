<?php

declare(strict_types=1);

namespace App\Domain\Patients\Queries;

use App\Domain\Patients\Data\TimelineCursor;
use App\Domain\Patients\Data\TimelineEntry;
use App\Domain\Patients\Data\TimelinePage;
use App\Domain\Patients\Services\TimelineSourceRegistry;
use App\Models\Tenant\Patient;

/**
 * PRESCRIPTION.md §8: merges every registered PatientTimelineSource, ordered occurred_at DESC with keyset cursor
 * (occurred_at, kind, id). Each source is asked for $limit + 1 rows older than the cursor (so "more" is detectable);
 * the union is sorted and cut.
 */
final class PatientTimelineQuery
{
    public const MAX_LIMIT = 100;

    public function __construct(private readonly TimelineSourceRegistry $registry) {}

    /** @param  array<int, string>|null  $kinds  restrict to these source kinds (null = all) */
    public function fetch(Patient $patient, ?string $cursor = null, int $limit = 25, ?array $kinds = null): TimelinePage
    {
        $limit = max(1, min(self::MAX_LIMIT, $limit));
        $after = TimelineCursor::decode($cursor);
        $entries = [];

        foreach ($this->registry->sources() as $source) {
            if ($kinds !== null && ! in_array($source->kind(), $kinds, true)) {
                continue;
            }

            foreach ($source->entries($patient, $after, $limit + 1) as $entry) {
                if ($after === null || $after->isAfter($entry)) {
                    $entries[] = $entry;
                }
            }
        }

        usort($entries, self::compare(...));

        $page = array_slice($entries, 0, $limit);
        $hasMore = count($entries) > $limit;

        return new TimelinePage($page, $hasMore && $page !== [] ? end($page)->cursor()->encode() : null);
    }

    /** @return array<int, string> */
    public function kinds(): array
    {
        return $this->registry->kinds();
    }

    /** DESC by occurred_at, then kind, then id — the cursor order. */
    private static function compare(TimelineEntry $a, TimelineEntry $b): int
    {
        return [$b->occurredAt->getPreciseTimestamp(6), $b->kind, $b->id] <=> [$a->occurredAt->getPreciseTimestamp(6), $a->kind, $a->id];
    }
}
