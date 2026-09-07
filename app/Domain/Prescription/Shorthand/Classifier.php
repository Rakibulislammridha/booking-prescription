<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Shorthand;

/**
 * Word classification against the keyword table + the "did you mean" suggestion for unknown tokens
 * (Levenshtein ≤ 1, or ≤ 2 for words of 5+ characters; ties → shortest, then alphabetical).
 */
final class Classifier
{
    public static function word(string $word): Token
    {
        $frequencies = Keywords::frequencies();

        if (isset($frequencies[$word])) {
            return new Token('freq', $word, ['code' => $frequencies[$word]['code'], 'per_day' => (int) $frequencies[$word]['per_day']]);
        }

        $all = Keywords::all();

        if (in_array($word, $all['stat'], true)) {
            return new Token('stat', $word);
        }

        if (in_array($word, $all['sos'], true)) {
            return new Token('sos', $word);
        }

        if (in_array($word, $all['hs'], true)) {
            return new Token('hs', $word);
        }

        $timing = Keywords::timingTokens();

        if (isset($timing[$word])) {
            return new Token('timing', $word, ['code' => $timing[$word][0], 'timing' => $timing[$word][1]]);
        }

        if (in_array($word, Keywords::routes(), true)) {
            return new Token('route', $word, ['code' => $word]);
        }

        return new Token('unknown', $word, ['suggestion' => self::suggest($word)]);
    }

    public static function suggest(string $word): ?string
    {
        if (preg_match('/^[a-z]+$/', $word) !== 1) {
            return null;
        }

        $limit = strlen($word) >= 5 ? 2 : 1;
        $best = null;
        $bestKey = null;

        foreach (Keywords::suggestibleWords() as $candidate) {
            $distance = levenshtein($word, $candidate);

            if ($distance === 0 || $distance > $limit) {
                continue;
            }

            $key = [$distance, strlen($candidate), $candidate];

            if ($bestKey === null || $key < $bestKey) {
                $bestKey = $key;
                $best = $candidate;
            }
        }

        return $best;
    }
}
