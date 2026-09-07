<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Exceptions\ImportBundleInvalid;

/**
 * The set of files one catalog:import run reads (CATALOG.md §5.1). Known names map to catalog tables; any other
 * *.csv is treated as a DGDA product list. The checksum fingerprints the whole bundle for the already-imported guard.
 */
final class Bundle
{
    public const KNOWN = [
        'routes', 'forms', 'generics', 'brands', 'icd10', 'interactions', 'allergy_classes', 'allergy_class_generics',
        'pregnancy', 'renal', 'hepatic', 'max_doses', 'drug_information',
    ];

    /** @var array<string, string> logical name → absolute file path */
    private array $files = [];

    /** @var list<string> product-list files (DGDA sheets) */
    private array $productFiles = [];

    private ?string $checksum = null;

    public function __construct(public readonly string $path)
    {
        $real = realpath($path);

        if ($real === false) {
            throw new ImportBundleInvalid("Import path does not exist: {$path}");
        }

        $candidates = is_dir($real) ? (glob($real.'/*.csv') ?: []) : [$real];

        foreach ($candidates as $file) {
            $name = strtolower(pathinfo($file, PATHINFO_FILENAME));

            if (in_array($name, self::KNOWN, true)) {
                $this->files[$name] = $file;
            } elseif (str_ends_with(strtolower($file), '.csv')) {
                $this->productFiles[] = $file;
            } else {
                throw new ImportBundleInvalid('Only CSV files are supported (convert XLSX sheets to CSV first): '.basename($file));
            }
        }

        if ($this->files === [] && $this->productFiles === []) {
            throw new ImportBundleInvalid("No CSV files found under {$path}");
        }

        sort($this->productFiles);
    }

    public function has(string $name): bool
    {
        return isset($this->files[$name]);
    }

    public function file(string $name): string
    {
        return $this->files[$name] ?? throw new ImportBundleInvalid("Bundle has no {$name}.csv");
    }

    /** @return list<string> */
    public function productFiles(): array
    {
        return $this->productFiles;
    }

    /** @return list<string> */
    public function allFiles(): array
    {
        $all = array_merge(array_values($this->files), $this->productFiles);
        sort($all);

        return $all;
    }

    /** True when the bundle carries brand/strength rows (seed brands.csv or a DGDA list). */
    public function hasProducts(): bool
    {
        return $this->has('brands') || $this->productFiles !== [];
    }

    public function checksum(): string
    {
        if ($this->checksum === null) {
            $ctx = hash_init('sha256');

            foreach ($this->allFiles() as $file) {
                hash_update($ctx, basename($file)."\n".hash_file('sha256', $file)."\n");
            }

            $this->checksum = hash_final($ctx);
        }

        return $this->checksum;
    }
}
