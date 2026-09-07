<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Domain\Serials\Exceptions\InvalidDisplayCode;

/**
 * `{session_code}-{number zero-padded to 3}` (SERIAL_ENGINE §5.2): ('A', 42) → 'A-042'; ('B', 1204) → 'B-1204'.
 * Stored on the row so old events print identically; Bangla digits are a presentation concern.
 */
final class DisplayCode
{
    public static function format(string $sessionCode, int $number): string
    {
        return sprintf('%s-%s', strtoupper($sessionCode), str_pad((string) $number, 3, '0', STR_PAD_LEFT));
    }

    /**
     * @return array{session_code: string, number: int}
     *
     * @throws InvalidDisplayCode
     */
    public static function parse(string $code): array
    {
        if (preg_match('/^([A-Za-z])-(\d{1,6})$/', trim($code), $m) !== 1) {
            throw new InvalidDisplayCode($code);
        }

        return ['session_code' => strtoupper($m[1]), 'number' => (int) $m[2]];
    }
}
