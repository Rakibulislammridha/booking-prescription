<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

/**
 * Anchored regex scanner over the normalised body (PRESCRIPTION.md §2.2). At every position the first matching rule
 * wins, in this order: slots · interval · qty · max · duration · amount · word · other. Amount tokens stand alone —
 * the Assembler binds a pending amount to the schedule keyword that follows it (`1 drop be tds`).
 */
final class Tokenizer
{
    private static ?string $units = null;

    private static ?string $durations = null;

    private static ?string $packs = null;

    private static ?string $hourWords = null;

    /** @return list<Token> */
    public static function tokenize(string $body): array
    {
        $tokens = [];
        $pos = 0;
        $len = mb_strlen($body);
        $num = '(?:\d+ \d+\/\d+|\d+\/\d+|\d+\.\d+|\d+)';
        $units = self::alternation(array_keys(Keywords::unitAliases()), self::$units);
        $amt = $num.'(?: ?'.$units.'\b)?';
        $durations = self::alternation(array_keys(array_filter(Keywords::durationWords(), fn ($v, $k) => $v[1] !== null, ARRAY_FILTER_USE_BOTH)), self::$durations);
        $openDurations = self::alternation(array_keys(array_filter(Keywords::durationWords(), fn ($v) => $v[1] === null)), self::$openDurationsRef);
        $packs = self::alternation(array_keys(Keywords::packWords()), self::$packs);
        $hours = self::alternation(Keywords::all()['interval']['hour_words'], self::$hourWords);
        $hrly = self::alternation(Keywords::all()['interval']['hrly'], self::$hrlyRef);

        $rules = [
            ['slots', '/\G('.$amt.'(?:\+'.$amt.')+)/u'],
            ['interval', '/\G(?:q(\d+)(?:'.$hours.')|(\d+)(?:'.$hrly.'))\b/u'],
            ['qty', '/\Gx(\d+)(?: ('.$packs.'))?\b/u'],
            ['max', '/\Gmax (\d+)\b/u'],
            ['duration', '/\G(?:(\d+) ?('.$durations.')|('.$openDurations.'))\b/u'],
            ['amount', '/\G(?:('.$num.')(?: ?('.$units.')\b)?|('.$units.')\b)/u'],
            ['word', '/\G([a-z]+)/u'],
            ['other', '/\G(\S+)/u'],
        ];

        while ($pos < $len) {
            $rest = mb_substr($body, $pos);

            if ($rest === '' || trim($rest) === '') {
                break;
            }

            if ($rest[0] === ' ') {
                $pos++;

                continue;
            }

            foreach ($rules as [$type, $regex]) {
                if (preg_match($regex, $rest, $m) !== 1) {
                    continue;
                }

                $text = $m[0];
                $tokens[] = self::build($type, $text, $m);
                $pos += mb_strlen($text);

                continue 2;
            }

            $pos++;                                                   // unreachable in practice: `other` matches any non-space
        }

        return $tokens;
    }

    /** @param  array<int|string, string>  $m */
    private static function build(string $type, string $text, array $m): Token
    {
        return match ($type) {
            'slots' => new Token('slots', $text, ['amounts' => array_map(self::amount(...), explode('+', $text))]),
            'interval' => new Token('interval', $text, ['hours' => (int) (($m[1] ?? '') !== '' ? $m[1] : $m[2])]),
            'qty' => new Token('qty', $text, ['value' => (int) $m[1], 'pack' => isset($m[2]) && $m[2] !== '' ? Keywords::packWords()[$m[2]] : null]),
            'max' => new Token('max', $text, ['value' => (int) $m[1]]),
            'duration' => self::duration($text, $m),
            'amount' => new Token('amount', $text, self::amount($text)),
            'word' => Classifier::word($text),
            default => new Token('unknown', $text, ['suggestion' => null]),
        };
    }

    /** @param  array<int|string, string>  $m */
    private static function duration(string $text, array $m): Token
    {
        $words = Keywords::durationWords();

        if (($m[1] ?? '') !== '') {
            [$kind, $perUnit] = $words[$m[2]];

            return new Token('duration', $text, ['kind' => 'days', 'days' => (int) $m[1] * (int) $perUnit, 'word' => $kind]);
        }

        [$kind] = $words[$m[3]];

        return new Token('duration', $text, ['kind' => $kind, 'days' => null, 'word' => $kind]);
    }

    /**
     * "2 tsp" | "500mg" | "1 1/2" | "apply" → value + canonical unit.
     *
     * @return array{value: float, unit: string|null, text: string}
     */
    private static function amount(string $text): array
    {
        $aliases = Keywords::unitAliases();

        if (preg_match('/^(\d+ \d+\/\d+|\d+\/\d+|\d+\.\d+|\d+) ?([a-z]+)?$/u', $text, $m) === 1) {
            $unit = isset($m[2]) ? ($aliases[$m[2]] ?? null) : null;

            return ['value' => NumberFormat::parse($m[1]), 'unit' => $unit, 'text' => $text];
        }

        return ['value' => 1.0, 'unit' => $aliases[$text] ?? null, 'text' => $text];
    }

    private static ?string $openDurationsRef = null;

    private static ?string $hrlyRef = null;

    /** @param  list<string>  $words */
    private static function alternation(array $words, ?string &$cache): string
    {
        if ($cache !== null) {
            return $cache;
        }

        usort($words, fn ($a, $b) => strlen($b) <=> strlen($a) ?: strcmp($a, $b));

        return $cache = '(?:'.implode('|', array_map(fn ($w) => preg_quote($w, '/'), $words)).')';
    }
}
