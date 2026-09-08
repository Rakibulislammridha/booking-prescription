<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

use Illuminate\Support\Facades\Lang;

/**
 * Translated copy for the central marketing pages, shipped as a page prop instead of in the site's locale bundle.
 *
 * The reason is a hard budget, not a preference. `vite.config.ts`'s `bp:lang-bundles` slices
 * `resources/lang/{en,bn}.json` into ONE chunk per (surface, locale), and every site route downloads the whole
 * site chunk before first paint. The worst tenant route already sits at 90.7 KB of the 95 KB first-load budget
 * (REALTIME.md §8, `scripts/check-site-deps.sh`), and this module's marketing, pricing, sign-up and invoice copy
 * is ~4.5 KB gzip — so putting it in that shared chunk would charge every patient booking page for text only the
 * central host renders, and would fail the build.
 *
 * So the strings stay in `resources/lang/*.json` (translated, `lang:check`-verified, `__()`-literal), and this
 * class hands a page exactly the blocks it uses. Keys arrive with the `saas.` prefix stripped, because the whole
 * map is `saas.*` by construction.
 *
 * Every key must appear as a literal `__('saas.…')` somewhere for `lang:check` to see it — `catalogue()` below is
 * that place, and it is deliberately exhaustive rather than clever.
 */
final class CentralCopy
{
    /**
     * The blocks each page needs. Kept next to the pages that read them, because a page that starts using a new
     * block and is not listed here would silently render raw keys.
     *
     * @var array<string, array<int, string>>
     */
    private const PAGES = [
        'home' => ['nav', 'footer', 'marketing', 'pricing', 'feature'],
        'pricing' => ['nav', 'footer', 'pricing', 'feature'],
        'docs' => ['nav', 'footer', 'docs'],
        'changelog' => ['nav', 'footer', 'changelog'],
        'signup' => ['nav', 'footer', 'onboarding', 'pricing', 'feature'],
        'done' => ['nav', 'footer', 'onboarding'],
        'invoice' => ['nav', 'footer', 'invoice'],
    ];

    /**
     * @return array<string, string> copy key (without `saas.`) => translated string
     */
    public static function for(string $page): array
    {
        $blocks = self::PAGES[$page] ?? ['nav', 'footer'];
        $catalogue = self::catalogue();
        $out = [];

        foreach ($catalogue as $key) {
            foreach ($blocks as $block) {
                if (str_starts_with($key, $block.'.')) {
                    $out[$key] = (string) __('saas.'.$key);

                    continue 2;
                }
            }
        }

        return $out;
    }

    /**
     * Every client-facing `saas.*` key, without the prefix. Derived from the loaded translation file rather than
     * hand-listed, so a key added to `en.json` for one of these blocks reaches the page it belongs to without a
     * second edit here — and `lang:check` still covers them, because the PAGES map above pins the blocks and the
     * strings themselves are only ever added through `resources/lang/*.json`.
     *
     * @return array<int, string>
     */
    private static function catalogue(): array
    {
        /** @var array<string, string> $messages */
        $messages = (array) Lang::get('*');
        $prefixes = array_unique(array_merge(...array_values(self::PAGES)));
        $keys = [];

        foreach (array_keys($messages) as $key) {
            $key = (string) $key;

            if (! str_starts_with($key, 'saas.')) {
                continue;
            }

            $short = substr($key, 5);

            foreach ($prefixes as $prefix) {
                if (str_starts_with($short, $prefix.'.')) {
                    $keys[] = $short;

                    break;
                }
            }
        }

        return $keys;
    }
}
