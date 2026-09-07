<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

use RuntimeException;

/**
 * The closed vocabulary of resources/shorthand/keywords.json (PRESCRIPTION.md §2.2) — the same file the TS parser
 * imports. Loaded once per process (immutable data, Octane-safe). Pure: no container, usable from unit tests.
 */
final class Keywords
{
    /** @var array<string, mixed>|null */
    private static ?array $data = null;

    /** @var array<string, string>|null token → category (for suggestions / duplicate detection) */
    private static ?array $vocabulary = null;

    public static function path(): string
    {
        return dirname(__DIR__, 4).'/resources/shorthand/keywords.json';
    }

    /** @return array<string, mixed> */
    public static function all(): array
    {
        if (self::$data === null) {
            $json = file_get_contents(self::path());

            if ($json === false) {
                throw new RuntimeException('keywords.json missing: '.self::path());
            }

            self::$data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        }

        return self::$data;
    }

    public static function version(): string
    {
        return (string) (self::all()['version'] ?? 1).'.'.substr(hash_file('sha256', self::path()) ?: '0', 0, 8);
    }

    /** @return array<string, array{code: string, per_day: int}> */
    public static function frequencies(): array
    {
        return self::all()['frequency'];
    }

    /**
     * unit alias → canonical unit.
     *
     * @return array<string, string>
     */
    public static function unitAliases(): array
    {
        $map = [];

        foreach (self::all()['units'] as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $map[$alias] = $canonical;
            }
        }

        return $map;
    }

    /** @return list<string> */
    public static function attachedUnits(): array
    {
        return self::all()['attached_units'];
    }

    /** @return array<string, float> mg multiplier per mass unit */
    public static function massUnits(): array
    {
        return array_map(fn ($v) => (float) $v, self::all()['mass_units']);
    }

    /**
     * duration word → [kind, days-per-unit|null].
     *
     * @return array<string, array{0: string, 1: int|null}>
     */
    public static function durationWords(): array
    {
        $map = [];

        foreach (self::all()['duration'] as $kind => $words) {
            foreach ($words as $word) {
                $map[$word] = [$kind, self::all()['duration_days'][$kind] ?? null];
            }
        }

        return $map;
    }

    /**
     * timing token → [code, timing column].
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function timingTokens(): array
    {
        $map = [];

        foreach (self::all()['timing'] as $code => $def) {
            foreach ($def['tokens'] as $token) {
                $map[$token] = [$code, $def['timing']];
            }
        }

        return $map;
    }

    /** @return list<string> */
    public static function routes(): array
    {
        return self::all()['routes'];
    }

    /**
     * pack word alias → canonical.
     *
     * @return array<string, string>
     */
    public static function packWords(): array
    {
        $map = [];

        foreach (self::all()['quantity']['pack_words'] as $canonical => $aliases) {
            foreach ($aliases as $alias) {
                $map[$alias] = $canonical;
            }
        }

        return $map;
    }

    /** @return array<string, array{default_unit: string, pack_unit: string, routes: list<string>}> */
    public static function forms(): array
    {
        return self::all()['forms'];
    }

    /** unit → family (counted | liquid | insulin | inhaler | packs). */
    public static function familyOf(string $unit): string
    {
        foreach (self::all()['families'] as $family => $units) {
            if (in_array($unit, $units, true)) {
                return $family;
            }
        }

        return 'counted';
    }

    /** @return array<string, float> */
    public static function liquidMl(): array
    {
        return array_map(fn ($v) => (float) $v, self::all()['liquid_ml']);
    }

    public static function packDays(): int
    {
        return (int) self::all()['pack_days'];
    }

    public static function defaultPackSize(string $family): ?float
    {
        $v = self::all()['default_pack_size'][$family] ?? null;

        return $v === null ? null : (float) $v;
    }

    /** @return array{en: string, bn: string} */
    public static function message(string $key): array
    {
        $m = self::all()['messages'][$key] ?? ['en' => $key, 'bn' => $key];

        return ['en' => (string) $m['en'], 'bn' => (string) $m['bn']];
    }

    /** @return array<string, mixed> */
    public static function labels(): array
    {
        return self::all()['labels'];
    }

    /**
     * Every keyword token → its category: used for typo suggestions and the KeywordsTest.
     *
     * @return array<string, string>
     */
    public static function vocabulary(): array
    {
        if (self::$vocabulary !== null) {
            return self::$vocabulary;
        }

        $v = [];
        $add = function (iterable $tokens, string $category) use (&$v): void {
            foreach ($tokens as $token) {
                $v[(string) $token] = $category;
            }
        };

        $add(array_keys(self::frequencies()), 'frequency');
        $add(self::all()['stat'], 'stat');
        $add(self::all()['sos'], 'sos');
        $add(self::all()['hs'], 'hs');
        $add(self::all()['max'], 'max');
        $add(array_keys(self::unitAliases()), 'unit');
        $add(array_filter(array_keys(self::durationWords()), fn ($w) => ! str_contains($w, ' ')), 'duration');
        $add(array_keys(self::timingTokens()), 'timing');
        $add(self::routes(), 'route');
        $add(array_keys(self::packWords()), 'pack');

        return self::$vocabulary = $v;
    }

    /**
     * Word tokens only (letters), for the Levenshtein suggestion of unknown tokens.
     *
     * @return list<string>
     */
    public static function suggestibleWords(): array
    {
        return array_values(array_filter(array_keys(self::vocabulary()), fn ($w) => preg_match('/^[a-z]+$/', $w) === 1 && strlen($w) >= 2));
    }
}
