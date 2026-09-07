<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

/**
 * PRESCRIPTION.md §2.1: Bangla digits → ASCII (that mapping alone defines `raw`), unicode fractions / × / dashes,
 * instruction split at the first `//` or quote, lower-case, whitespace collapse, digit/letter spacing outside the
 * attached-unit set, no spaces around `+` (and around `/` between digits).
 */
final class Normalizer
{
    /** @return array{raw: string, body: string, instruction: string|null} */
    public static function normalize(string $text): array
    {
        $raw = NumberFormat::enDigits($text);
        $work = strtr($raw, ['×' => 'x', '½' => '1/2', '¼' => '1/4', '¾' => '3/4', '—' => '-', '–' => '-']);

        $instruction = null;
        $split = self::instructionOffset($work);

        if ($split !== null) {
            [$offset, $length] = $split;
            $instruction = trim(mb_substr($work, $offset + $length));
            $instruction = rtrim($instruction, '"”“');
            $instruction = trim($instruction);
            $instruction = $instruction === '' ? null : $instruction;
            $work = mb_substr($work, 0, $offset);
        }

        $body = mb_strtolower($work);
        $body = trim((string) preg_replace('/\s+/u', ' ', $body));
        $body = (string) preg_replace('/\s*\+\s*/u', '+', $body);
        $body = (string) preg_replace('/(\d)\s*\/\s*(\d)/u', '$1/$2', $body);
        $attached = Keywords::attachedUnits();
        $body = (string) preg_replace_callback('/(\d)([a-z]+)/u', function (array $m) use ($attached): string {
            return in_array($m[2], $attached, true) ? $m[0] : $m[1].' '.$m[2];
        }, $body);

        return ['raw' => $raw, 'body' => trim($body), 'instruction' => $instruction];
    }

    /** @return array{0: int, 1: int}|null char offset and length of the instruction marker */
    private static function instructionOffset(string $text): ?array
    {
        $best = null;

        foreach (['//' => 2, '"' => 1, '“' => 1, '”' => 1] as $marker => $length) {
            $pos = mb_strpos($text, $marker);

            if ($pos !== false && ($best === null || $pos < $best[0])) {
                $best = [$pos, $length];
            }
        }

        return $best;
    }
}
