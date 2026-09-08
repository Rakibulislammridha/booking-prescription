<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

/**
 * The documentation shell BRIEF §5.M asks for: a small, translated, versioned set of sections served from code.
 *
 * Not Markdown files and not a CMS. The content is short, it must exist in Bangla and English with the same
 * structure, and `php artisan lang:check` already guarantees that every key exists in both — which is a stronger
 * promise than "someone remembered to translate the .md". When the docs outgrow this (they will), the shape here
 * is exactly what a Markdown loader would have to produce anyway.
 */
final class DocsLibrary
{
    /** @var array<int, array{slug: string, blocks: array<int, array{kind: string, count: int}>}> */
    private const SECTIONS = [
        ['slug' => 'getting-started', 'blocks' => [['kind' => 'para', 'count' => 0], ['kind' => 'steps', 'count' => 5], ['kind' => 'note', 'count' => 0]]],
        ['slug' => 'serials-and-queue', 'blocks' => [['kind' => 'para', 'count' => 0], ['kind' => 'steps', 'count' => 4], ['kind' => 'note', 'count' => 0]]],
        ['slug' => 'prescriptions', 'blocks' => [['kind' => 'para', 'count' => 0], ['kind' => 'steps', 'count' => 4], ['kind' => 'note', 'count' => 0]]],
        ['slug' => 'offline-reception', 'blocks' => [['kind' => 'para', 'count' => 0], ['kind' => 'steps', 'count' => 3], ['kind' => 'note', 'count' => 0]]],
        ['slug' => 'billing-and-plans', 'blocks' => [['kind' => 'para', 'count' => 0], ['kind' => 'steps', 'count' => 4], ['kind' => 'note', 'count' => 0]]],
        ['slug' => 'custom-domain', 'blocks' => [['kind' => 'para', 'count' => 0], ['kind' => 'steps', 'count' => 4], ['kind' => 'note', 'count' => 0]]],
    ];

    /** @return array<int, string> */
    public static function slugs(): array
    {
        return array_map(fn (array $s): string => $s['slug'], self::SECTIONS);
    }

    public static function has(string $slug): bool
    {
        return in_array($slug, self::slugs(), true);
    }

    public static function default(): string
    {
        return self::SECTIONS[0]['slug'];
    }

    /**
     * Section metadata for the sidebar (title only, no bodies).
     *
     * @param  callable(string): string  $url
     * @return array<int, array{slug: string, title: string, url: string, blocks: array<int, mixed>}>
     */
    public static function index(callable $url): array
    {
        return array_map(fn (array $section): array => [
            'slug' => $section['slug'],
            'title' => (string) __('saas.handbook.'.$section['slug'].'.title'),
            'url' => $url($section['slug']),
            'blocks' => [],
        ], self::SECTIONS);
    }

    /**
     * One section, fully rendered into translated strings — the page never calls `t()` on doc bodies.
     *
     * @param  callable(string): string  $url
     * @return array{slug: string, title: string, url: string, blocks: array<int, array{kind: string, text?: string, items?: array<int, string>}>}
     */
    public static function section(string $slug, callable $url): array
    {
        $definition = null;

        foreach (self::SECTIONS as $candidate) {
            if ($candidate['slug'] === $slug) {
                $definition = $candidate;
                break;
            }
        }

        $definition ??= self::SECTIONS[0];
        $prefix = 'saas.handbook.'.$definition['slug'].'.';
        $blocks = [];
        $paragraph = 0;

        foreach ($definition['blocks'] as $block) {
            if ($block['kind'] === 'steps') {
                $items = [];

                for ($i = 1; $i <= $block['count']; $i++) {
                    $items[] = (string) __($prefix.'step.'.$i);
                }

                $blocks[] = ['kind' => 'steps', 'items' => $items];

                continue;
            }

            if ($block['kind'] === 'note') {
                $blocks[] = ['kind' => 'note', 'text' => (string) __($prefix.'note')];

                continue;
            }

            $paragraph++;
            $blocks[] = ['kind' => 'para', 'text' => (string) __($prefix.'para.'.$paragraph)];
        }

        return [
            'slug' => $definition['slug'],
            'title' => (string) __($prefix.'title'),
            'url' => $url($definition['slug']),
            'blocks' => $blocks,
        ];
    }
}
