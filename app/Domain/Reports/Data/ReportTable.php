<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use Closure;

/**
 * One exportable table: the same header row and the same cells for CSV, Excel and PDF, so the three files can
 * never disagree with each other or with the screen (a report that shows a wrong number is worse than no
 * report, and three writers each formatting their own cells is exactly how that happens).
 *
 * `$rows` is a CLOSURE that yields a fresh generator on every call: the CSV and XLSX writers pull it lazily,
 * so a three-year export never materialises in memory, and a second pass (HTML preview then PDF) still sees
 * the whole table instead of an exhausted generator.
 *
 * @phpstan-type Cell string|int|float|null
 */
final readonly class ReportTable
{
    /**
     * @param  array<int, string>  $columns  header labels, already translated
     * @param  Closure(): iterable<int, array<int, string|int|float|null>>  $rows  a FACTORY, called once per pass
     * @param  array<int, string>  $align  'left'|'right' per column; defaults to left
     * @param  array<int, string>  $notes  the metric definitions printed under the table
     */
    public function __construct(
        public string $title,
        public string $subtitle,
        public array $columns,
        private Closure $rows,
        public array $align = [],
        public array $notes = [],
        public ?int $knownRowCount = null,
    ) {}

    /**
     * A FRESH pass over the rows. It is a factory rather than a stored generator because a generator can only
     * be walked once: rendering the HTML and then the PDF from the same table would silently produce an empty
     * second document, and an empty report that looks like a report is the worst failure this module has.
     *
     * @return iterable<int, array<int, string|int|float|null>>
     */
    public function rows(): iterable
    {
        return ($this->rows)();
    }

    public function alignFor(int $index): string
    {
        return $this->align[$index] ?? 'left';
    }

    /** A filesystem-safe basename; the extension is the writer's business. */
    public function basename(string $report, string $from, string $to): string
    {
        return sprintf('%s-%s-%s', preg_replace('/[^a-z0-9-]+/', '-', $report) ?? 'report', $from, $to);
    }
}
