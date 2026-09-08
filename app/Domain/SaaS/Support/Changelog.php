<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

/**
 * The in-app changelog (BRIEF §5.M). Entries live here, newest first, with translated bodies — a clinic that
 * runs in Bangla should read its release notes in Bangla.
 *
 * A release adds one entry and its `saas.changelog.entry.*` keys in the same commit, which `lang:check` then
 * enforces in both locales.
 */
final class Changelog
{
    /** @var array<int, array{version: string, date: string, changes: array<int, array{kind: string, key: string}>}> */
    private const ENTRIES = [
        [
            'version' => '1.4.0',
            'date' => '2026-09-08',
            'changes' => [
                ['kind' => 'added', 'key' => 'saas.release.1_4_0.1'],
                ['kind' => 'added', 'key' => 'saas.release.1_4_0.2'],
                ['kind' => 'added', 'key' => 'saas.release.1_4_0.3'],
                ['kind' => 'improved', 'key' => 'saas.release.1_4_0.4'],
            ],
        ],
        [
            'version' => '1.3.0',
            'date' => '2026-08-24',
            'changes' => [
                ['kind' => 'added', 'key' => 'saas.release.1_3_0.1'],
                ['kind' => 'added', 'key' => 'saas.release.1_3_0.2'],
                ['kind' => 'improved', 'key' => 'saas.release.1_3_0.3'],
            ],
        ],
        [
            'version' => '1.2.0',
            'date' => '2026-08-05',
            'changes' => [
                ['kind' => 'added', 'key' => 'saas.release.1_2_0.1'],
                ['kind' => 'improved', 'key' => 'saas.release.1_2_0.2'],
                ['kind' => 'fixed', 'key' => 'saas.release.1_2_0.3'],
            ],
        ],
    ];

    /** @return array<int, array{version: string, date: string, changes: array<int, array{kind: string, text: string}>}> */
    public static function entries(): array
    {
        return array_map(fn (array $entry): array => [
            'version' => $entry['version'],
            'date' => $entry['date'].'T00:00:00Z',
            'changes' => array_map(fn (array $change): array => [
                'kind' => $change['kind'],
                'text' => (string) __($change['key']),
            ], $entry['changes']),
        ], self::ENTRIES);
    }

    public static function latestVersion(): string
    {
        return self::ENTRIES[0]['version'];
    }
}
