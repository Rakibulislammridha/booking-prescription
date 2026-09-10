<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * Outcome of CatalogImporter::run(). status: applied | dry_run | already_imported.
 */
final class ImportReport
{
    /**
     * @param  array<string, array{rows: int, inserted: int, updated: int, deactivated: int}>  $rowCounts
     * @param  array<string, list<int>>  $changedIds  table → ids inserted/updated/deactivated (for incremental reindex)
     * @param  array<string, int>  $issues  kind → count
     * @param  list<array{kind: string, source_row: int|null, payload: array<string, mixed>}>  $issueSamples  the first issues, kept out of the transaction so a dry run can show them
     */
    public function __construct(
        public string $status,
        public ?int $versionId,
        public string $version,
        public string $checksum,
        public array $rowCounts = [],
        public array $changedIds = [],
        public array $issues = [],
        public int $durationMs = 0,
        public array $issueSamples = [],
    ) {}

    public function isApplied(): bool
    {
        return $this->status === 'applied';
    }

    public function totalChanges(): int
    {
        $total = 0;

        foreach ($this->rowCounts as $counts) {
            $total += $counts['inserted'] + $counts['updated'] + $counts['deactivated'];
        }

        return $total;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status, 'version_id' => $this->versionId, 'version' => $this->version, 'checksum' => $this->checksum,
            'row_counts' => $this->rowCounts, 'issues' => $this->issues, 'duration_ms' => $this->durationMs,
            'issue_samples' => $this->issueSamples, 'total_changes' => $this->totalChanges(),
        ];
    }
}
