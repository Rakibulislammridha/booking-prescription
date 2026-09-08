<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Reports\Data\ReportTable;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV, streamed (BRIEF §5.L "export to CSV" — and §8's low-end reality: a three-year export must not be held
 * in PHP's memory on a 2 GB VPS). The rows arrive from a generator and go straight to `php://output`.
 *
 * The UTF-8 BOM is not decoration: without it Excel on a Bangladeshi desktop opens `ডা. রহমান` as mojibake, and
 * a report the clinic cannot read is a report that does not exist.
 */
final class CsvWriter
{
    public function response(ReportTable $table, string $filename): StreamedResponse
    {
        return response()->streamDownload(function () use ($table): void {
            $handle = fopen('php://output', 'wb');

            if ($handle === false) {
                return;
            }

            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, [$table->title], ',', '"', '\\');
            fputcsv($handle, [$table->subtitle], ',', '"', '\\');
            fputcsv($handle, [], ',', '"', '\\');
            fputcsv($handle, $table->columns, ',', '"', '\\');

            foreach ($table->rows() as $row) {
                fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '\\');
                flush();
            }

            if ($table->notes !== []) {
                fputcsv($handle, [], ',', '"', '\\');

                foreach ($table->notes as $note) {
                    fputcsv($handle, [$note], ',', '"', '\\');
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** Write the same bytes to a file, for an export the `reports` queue produced. */
    public function toFile(ReportTable $table, string $path): int
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            return 0;
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, [$table->title], ',', '"', '\\');
        fputcsv($handle, [$table->subtitle], ',', '"', '\\');
        fputcsv($handle, [], ',', '"', '\\');
        fputcsv($handle, $table->columns, ',', '"', '\\');
        $rows = 0;

        foreach ($table->rows() as $row) {
            fputcsv($handle, array_map(self::cell(...), $row), ',', '"', '\\');
            $rows++;
        }

        // The same trailing shape the streamed response writes, blank separator included: a queued export and
        // a downloaded one are the same report, and a diff between the two files should be empty.
        if ($table->notes !== []) {
            fputcsv($handle, [], ',', '"', '\\');

            foreach ($table->notes as $note) {
                fputcsv($handle, [$note], ',', '"', '\\');
            }
        }

        fclose($handle);

        return $rows;
    }

    private static function cell(string|int|float|null $value): string
    {
        return $value === null ? '' : (string) $value;
    }
}
