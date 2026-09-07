<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use App\Domain\Catalog\Enums\ImportSource;

/** Arguments of one catalog:import run (CATALOG.md §5). */
final readonly class ImportRequest
{
    public function __construct(
        public string $path,
        public ImportSource $source = ImportSource::Manual,
        public ?string $version = null,
        public bool $full = false,
        public bool $dryRun = false,
        public bool $force = false,
        public bool $reindex = false,
        public ?string $appliedBy = null,
        public ?string $releaseRef = null,
        public ?string $notes = null,
    ) {}
}
