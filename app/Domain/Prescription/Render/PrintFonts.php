<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

/**
 * PRESCRIPTION.md §7.2 — the `@font-face` block of the printed sheet. Bangla rendering is a product signal
 * (BRIEF §2, §8), so nothing here may depend on a network fetch at render time:
 *
 *  - `local()` comes first, so a system-installed Noto Sans Bengali (the deployment target has it) is used directly;
 *  - a bundled woff2 under `public/fonts/**` is the guaranteed fallback, so a host without the system font still
 *    prints joined Bangla conjuncts rather than tofu boxes;
 *  - for `purpose=pdf` the faces are inlined as data URIs, because Browsershot renders an HTML *string* with no
 *    base URL and a relative `/fonts/...` would silently resolve to nothing. Print and verify link the same files
 *    over HTTP so the browser caches them (the verification page is public and must open on a poor connection).
 *
 * Both paths load byte-identical font files, so the PDF and the browser print are the same typography.
 */
final class PrintFonts
{
    /** @var array<string, array{family: string, weight: int, file: string, range: string|null}> */
    private const FACES = [
        'inter-400' => ['family' => 'Inter', 'weight' => 400, 'file' => 'fonts/inter/inter-latin-400-normal.woff2', 'range' => null],
        'inter-600' => ['family' => 'Inter', 'weight' => 600, 'file' => 'fonts/inter/inter-latin-600-normal.woff2', 'range' => null],
        'inter-700' => ['family' => 'Inter', 'weight' => 700, 'file' => 'fonts/inter/inter-latin-700-normal.woff2', 'range' => null],
        'bengali-400' => ['family' => 'Noto Sans Bengali', 'weight' => 400, 'file' => 'fonts/noto-sans-bengali/noto-sans-bengali-bengali-400-normal.woff2', 'range' => 'U+0964-0965, U+0980-09FF, U+200C-200D, U+20B9, U+25CC'],
        'bengali-600' => ['family' => 'Noto Sans Bengali', 'weight' => 600, 'file' => 'fonts/noto-sans-bengali/noto-sans-bengali-bengali-600-normal.woff2', 'range' => 'U+0964-0965, U+0980-09FF, U+200C-200D, U+20B9, U+25CC'],
        'bengali-700' => ['family' => 'Noto Sans Bengali', 'weight' => 700, 'file' => 'fonts/noto-sans-bengali/noto-sans-bengali-bengali-700-normal.woff2', 'range' => 'U+0964-0965, U+0980-09FF, U+200C-200D, U+20B9, U+25CC'],
    ];

    /** @var array<string, string> base64 payloads, memoised per process (Octane-safe: the files never change at runtime) */
    private static array $encoded = [];

    public function css(bool $inline): string
    {
        $css = '';

        foreach (self::FACES as $key => $face) {
            $path = public_path($face['file']);
            $src = "local('{$face['family']}')";

            if (is_file($path)) {
                $url = $inline ? 'data:font/woff2;base64,'.$this->encoded($key, $path) : '/'.$face['file'];
                $src .= ", url('{$url}') format('woff2')";
            }

            $range = $face['range'] !== null ? "unicode-range:{$face['range']};" : '';
            $css .= "@font-face{font-family:'{$face['family']}';font-style:normal;font-weight:{$face['weight']};font-display:block;src:{$src};{$range}}";
        }

        return $css;
    }

    private function encoded(string $key, string $path): string
    {
        return self::$encoded[$key] ??= base64_encode((string) file_get_contents($path));
    }
}
