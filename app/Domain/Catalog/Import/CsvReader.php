<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Exceptions\ImportBundleInvalid;
use Generator;

/**
 * UTF-8 CSV with a header row → generator of [line => assoc row]. Header names are normalised to snake_case so
 * `DAR No.`, `dar_number` and `DAR Number` all map to the same key; a BOM is stripped.
 */
final class CsvReader
{
    /** @return Generator<int, array<string, string>> keyed by 1-based line number of the data row */
    public function rows(string $file): Generator
    {
        $handle = @fopen($file, 'rb');

        if ($handle === false) {
            throw new ImportBundleInvalid("Cannot open {$file}");
        }

        try {
            $header = fgetcsv($handle, null, ',', '"', '\\');

            if ($header === false || $header === [null]) {
                throw new ImportBundleInvalid("Empty file: {$file}");
            }

            $header = array_map(fn (?string $h) => self::normaliseHeader((string) $h), $header);
            $line = 1;

            while (($record = fgetcsv($handle, null, ',', '"', '\\')) !== false) {
                $line++;

                if ($record === [null] || (count($record) === 1 && trim((string) $record[0]) === '')) {
                    continue;
                }

                $row = [];

                foreach ($header as $i => $key) {
                    if ($key === '') {
                        continue;
                    }

                    $row[$key] = trim((string) ($record[$i] ?? ''));
                }

                yield $line => $row;
            }
        } finally {
            fclose($handle);
        }
    }

    public static function normaliseHeader(string $header): string
    {
        $header = preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header;
        $header = strtolower(trim($header));
        $header = preg_replace('/[^a-z0-9]+/', '_', $header) ?? $header;

        return trim($header, '_');
    }
}
