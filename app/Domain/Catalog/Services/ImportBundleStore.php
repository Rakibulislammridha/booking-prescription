<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\ImportBundleInvalid;
use App\Domain\Catalog\Import\Bundle;
use App\Domain\Catalog\Import\CsvReader;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use ZipArchive;

/**
 * Where a bundle uploaded from the console lives until Horizon imports it: `storage/app/private/catalog-imports/
 * {ulid}/` holding the CSV files exactly as `catalog:import {path}` reads them (CATALOG.md §5.1 — one directory,
 * CSVs named after their tables, any other CSV a DGDA product sheet). A `.zip` of CSVs is unpacked flat.
 *
 * Validation is the importer's own rules applied BEFORE anything is queued: only CSV, a header row, the columns
 * a known file needs (the importer indexes `$r['code']` etc. and would fail three tables in), and a product sheet
 * that the DGDA mapper can at least find a brand and a generic column in. A bundle that fails here is deleted.
 */
final class ImportBundleStore
{
    /** @var array<string, array<int, string>> known file → columns the importer reads unconditionally */
    public const REQUIRED_COLUMNS = [
        'routes' => ['code', 'name', 'abbreviation'],
        'forms' => ['code', 'name', 'abbreviation', 'default_unit'],
        'generics' => ['name'],
        'brands' => ['name', 'generic_slug'],
        'icd10' => ['code', 'title'],
        'interactions' => ['generic_a', 'generic_b', 'severity', 'effect'],
        'allergy_classes' => ['slug', 'name'],
        'allergy_class_generics' => ['class_slug', 'generic_slug'],
        'pregnancy' => ['generic_slug', 'category'],
        'renal' => ['generic_slug', 'level', 'advice'],
        'hepatic' => ['generic_slug', 'level', 'advice'],
        'max_doses' => ['generic_slug'],
        'drug_information' => ['generic_slug'],
    ];

    /** A DGDA sheet needs a brand column and a generic column under one of the mapper's aliases. */
    private const PRODUCT_BRAND = ['brand', 'brand_name', 'product', 'product_name', 'trade_name'];

    private const PRODUCT_GENERIC = ['generic', 'generic_name', 'generic_names', 'composition', 'active_ingredient', 'ingredients'];

    public const MAX_FILES = 20;

    public function __construct(private readonly CsvReader $csv) {}

    public function root(): string
    {
        return storage_path('app/private/catalog-imports');
    }

    /**
     * Store the upload under a fresh directory and validate it as a bundle.
     *
     * @param  array<int, UploadedFile>  $files
     * @return array{path: string, files: list<string>, checksum: string, rows: array<string, int>}
     */
    public function store(array $files): array
    {
        $dir = $this->root().'/'.Str::lower((string) Str::ulid());

        if (! is_dir($dir) && ! mkdir($dir, 0750, true) && ! is_dir($dir)) {
            throw new ImportBundleInvalid('Could not create the import directory');
        }

        try {
            foreach ($files as $file) {
                $this->place($file, $dir);
            }

            return ['path' => $dir] + $this->validate($dir);
        } catch (\Throwable $e) {
            $this->delete($dir);

            throw $e;
        }
    }

    /**
     * The importer's own reading of the directory, plus a header check per file.
     *
     * @return array{files: list<string>, checksum: string, rows: array<string, int>}
     */
    public function validate(string $dir): array
    {
        $bundle = new Bundle($dir);
        $rows = [];

        foreach ($bundle->allFiles() as $file) {
            $name = strtolower(pathinfo($file, PATHINFO_FILENAME));
            $count = 0;
            $header = null;

            foreach ($this->csv->rows($file) as $row) {
                $header ??= array_keys($row);
                $count++;
            }

            if ($header === null) {
                throw new ImportBundleInvalid(basename($file).': no data rows under the header');
            }

            $required = self::REQUIRED_COLUMNS[$name] ?? null;

            if ($required !== null) {
                $missing = array_values(array_diff($required, $header));

                if ($missing !== []) {
                    throw new ImportBundleInvalid(basename($file).': missing column(s) '.implode(', ', $missing));
                }
            } elseif (array_intersect(self::PRODUCT_BRAND, $header) === [] || array_intersect(self::PRODUCT_GENERIC, $header) === []) {
                throw new ImportBundleInvalid(basename($file).': a product sheet needs a brand column and a generic column');
            }

            $rows[basename($file)] = $count;
        }

        return ['files' => array_map('basename', $bundle->allFiles()), 'checksum' => $bundle->checksum(), 'rows' => $rows];
    }

    public function delete(string $dir): void
    {
        $real = realpath($dir);

        if ($real === false || ! str_starts_with($real, $this->root())) {
            return;
        }

        foreach (glob($real.'/*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($real);
    }

    private function place(UploadedFile $file, string $dir): void
    {
        $original = strtolower((string) $file->getClientOriginalName());
        $extension = pathinfo($original, PATHINFO_EXTENSION);

        if ($extension === 'zip') {
            $this->unzip($file->getRealPath() ?: $file->getPathname(), $dir);

            return;
        }

        if ($extension !== 'csv') {
            throw new ImportBundleInvalid('Only CSV files (or a zip of CSV files) are supported: '.$original);
        }

        $target = $dir.'/'.self::safeName($original);

        if (! copy($file->getRealPath() ?: $file->getPathname(), $target)) {
            throw new ImportBundleInvalid('Could not store '.$original);
        }
    }

    private function unzip(string $zipPath, string $dir): void
    {
        $zip = new ZipArchive;

        if ($zip->open($zipPath) !== true) {
            throw new ImportBundleInvalid('The zip archive could not be opened');
        }

        try {
            $placed = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);
                $base = strtolower(basename($entry));

                if (str_ends_with($entry, '/') || str_starts_with($base, '.') || str_contains($entry, '__MACOSX')) {
                    continue;
                }

                if (! str_ends_with($base, '.csv')) {
                    throw new ImportBundleInvalid('The zip may contain CSV files only: '.$entry);
                }

                if (++$placed > self::MAX_FILES) {
                    throw new ImportBundleInvalid('Too many files in the zip (max '.self::MAX_FILES.')');
                }

                $stream = $zip->getStream($entry);

                if ($stream === false) {
                    throw new ImportBundleInvalid('Could not read '.$entry.' from the zip');
                }

                $target = fopen($dir.'/'.self::safeName($base), 'wb');

                if ($target === false) {
                    fclose($stream);

                    throw new ImportBundleInvalid('Could not store '.$entry);
                }

                stream_copy_to_stream($stream, $target);
                fclose($stream);
                fclose($target);
            }
        } finally {
            $zip->close();
        }
    }

    /** `Brands (2).CSV` → `brands-2.csv`; the table-named files keep their exact names so Bundle recognises them. */
    private static function safeName(string $name): string
    {
        $stem = strtolower(pathinfo($name, PATHINFO_FILENAME));

        if (in_array($stem, Bundle::KNOWN, true)) {
            return $stem.'.csv';
        }

        $slug = Str::slug($stem);

        return ($slug !== '' ? $slug : 'products').'.csv';
    }
}
