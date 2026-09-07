<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Data\ImportRow;
use Closure;

/**
 * brands.csv of the development bundle: `generic_slug,name,manufacturer,dar_number,popularity,aliases,presentations,status`.
 * `presentations` is `form:label[:pack]; …` (CATALOG.md §3.2); blank → the generic's default_presentations.
 */
final class SeedRowMapper implements RowMapper
{
    /** @param  Closure(string): ?string  $defaultPresentations  generic slug → default presentations string */
    public function __construct(private readonly Closure $defaultPresentations) {}

    public function map(array $record, int $line): iterable
    {
        $slug = $record['generic_slug'] ?? '';
        $presentations = $record['presentations'] ?? '';

        if ($presentations === '') {
            $presentations = ($this->defaultPresentations)($slug) ?? '';
        }

        $aliases = array_values(array_filter(array_map('trim', explode('|', $record['aliases'] ?? '')), fn ($a) => $a !== ''));
        $popularity = ($record['popularity'] ?? '') === '' ? null : (int) $record['popularity'];

        foreach (self::presentations($presentations) as [$form, $label, $pack]) {
            yield new ImportRow(
                sourceRow: $line,
                manufacturer: ($record['manufacturer'] ?? '') === '' ? null : $record['manufacturer'],
                brand: $record['name'] ?? '',
                genericText: $slug,
                strengthLabel: $label,
                formText: $form,
                routeText: ($record['route'] ?? '') === '' ? null : $record['route'],
                packSize: $pack,
                darNumber: ($record['dar_number'] ?? '') === '' ? null : $record['dar_number'],
                status: ($record['status'] ?? '') === '' ? 'active' : $record['status'],
                genericSlug: $slug,
                popularity: $popularity,
                aliases: $aliases,
            );
        }
    }

    /** @return list<array{0: string, 1: string, 2: string|null}> [form_code, label, pack] */
    public static function presentations(string $spec): array
    {
        $out = [];

        foreach (explode(';', $spec) as $part) {
            $part = trim($part);

            if ($part === '') {
                continue;
            }

            $bits = array_map('trim', explode(':', $part, 3));
            $out[] = [$bits[0], $bits[1] ?? '', ($bits[2] ?? '') === '' ? null : $bits[2]];
        }

        return $out;
    }
}
