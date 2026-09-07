<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use App\Domain\Catalog\Data\ImportRequest;
use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Import\CatalogImporter;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;

/**
 * catalog:import {path} — CATALOG.md §5. Versioned, idempotent; --full deactivates what the bundle omits.
 */
final class ImportCommand extends Command
{
    protected $signature = 'catalog:import {path : Directory of CSV files (or one CSV)}
        {--source=manual : dgda|seed|manual}
        {--catalog-version= : catalog_versions.version (default source.timestamp; --version is reserved by Symfony Console)}
        {--release-ref= : DGDA bulletin / gazette reference}
        {--full : The bundle is the complete list; rows it omits are deactivated (never deleted)}
        {--dry-run : Compute the would-be counts and roll back}
        {--force : Import even if this bundle checksum was imported before}
        {--reindex : Rebuild the Meilisearch indexes afterwards}';

    protected $description = 'Import a catalog bundle (DGDA list or the seed CSVs) through the versioned upsert pipeline';

    public function handle(CatalogImporter $importer): int
    {
        $source = ImportSource::tryFrom((string) $this->option('source'));

        if ($source === null) {
            $this->components->error('--source must be dgda, seed or manual');

            return self::INVALID;
        }

        /** @var string|null $email */
        $email = Auth::guard('super')->user()?->getAttribute('email');

        $request = new ImportRequest(
            path: (string) $this->argument('path'),
            source: $source,
            version: $this->option('catalog-version') !== null ? (string) $this->option('catalog-version') : null,
            full: (bool) $this->option('full'),
            dryRun: (bool) $this->option('dry-run'),
            force: (bool) $this->option('force'),
            reindex: (bool) $this->option('reindex'),
            appliedBy: $email ?? 'console:'.(get_current_user() ?: 'unknown'),
            releaseRef: $this->option('release-ref') !== null ? (string) $this->option('release-ref') : null,
        );

        try {
            $report = $importer->run($request);
        } catch (DomainException $e) {
            $this->components->error($e->getMessage().' ['.$e->code().']');

            return self::FAILURE;
        }

        if ($report->status === 'already_imported') {
            $this->components->warn("Already imported as catalog version {$report->version} (checksum {$report->checksum}); use --force to re-run.");

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($report->rowCounts as $table => $c) {
            $rows[] = [$table, $c['rows'], $c['inserted'], $c['updated'], $c['deactivated']];
        }

        $this->table(['table', 'rows', 'inserted', 'updated', 'deactivated'], $rows);
        $issues = $report->issues === [] ? 'none' : collect($report->issues)->map(fn ($c, $k) => "{$k}={$c}")->implode(', ');
        $verb = $report->status === 'dry_run' ? 'Dry run (rolled back)' : 'Applied';
        $this->components->info("{$verb} catalog version {$report->version} in {$report->durationMs} ms; issues: {$issues}.");

        return self::SUCCESS;
    }
}
