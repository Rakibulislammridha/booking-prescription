<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Data\SegmentCount;

/**
 * SMS encoding and segment maths — a billing-correctness component, not a cosmetic counter. A clinic is invoiced
 * per segment by its gateway, and Bangla is UCS-2: **70 characters per single-part message, 67 per part once the
 * message is concatenated**, against 160/153 for GSM-7. Getting this wrong silently triples a clinic's SMS bill.
 *
 * Rules implemented (GSM 03.38 / 3GPP TS 23.040 §9.2.3.24):
 *  - A body is GSM-7 only if EVERY character is in the basic set or the escape-extension set.
 *  - An extension character (^ { } \ [ ~ ] | €) costs TWO GSM-7 septets, and a concatenated part may not split
 *    the escape from its character — the pair moves to the next part instead, which shortens the earlier part.
 *  - Otherwise the body is UCS-2 and the unit is the UTF-16 **code unit**: BMP characters (all Bengali) cost 1,
 *    astral characters (emoji) cost 2, and a surrogate pair is never split across parts.
 *  - Concatenation costs a 6-byte UDH, hence 153 septets / 67 code units per part.
 */
final class SegmentCounter
{
    public const GSM_SINGLE = 160;

    public const GSM_MULTI = 153;

    public const UCS2_SINGLE = 70;

    public const UCS2_MULTI = 67;

    /**
     * GSM 03.38 basic character set (the 128 septets). Built from single-quoted parts with the two control
     * positions concatenated in: a double-quoted literal here would contain a bare `$` and is fragile under
     * formatters.
     */
    private const GSM_BASIC = '@£$¥èéùìòÇ'."\n".'Øø'."\r".'ÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';

    /** Characters reachable only through the 0x1B escape — two septets each. */
    private const GSM_EXTENDED = "^{}\\[~]|€\f";

    public function count(string $body): SegmentCount
    {
        return $this->isGsm7($body) ? $this->countGsm($body) : $this->countUcs2($body);
    }

    public function isGsm7(string $body): bool
    {
        foreach ($this->characters($body) as $char) {
            if (! str_contains(self::GSM_BASIC, $char) && ! str_contains(self::GSM_EXTENDED, $char)) {
                return false;
            }
        }

        return true;
    }

    /** True when the body contains at least one Bengali code point (U+0980–U+09FF). */
    public function containsBengali(string $body): bool
    {
        return preg_match('/[\x{0980}-\x{09FF}]/u', $body) === 1;
    }

    private function countGsm(string $body): SegmentCount
    {
        $costs = [];

        foreach ($this->characters($body) as $char) {
            $costs[] = str_contains(self::GSM_EXTENDED, $char) ? 2 : 1;
        }

        $units = array_sum($costs);

        if ($units <= self::GSM_SINGLE) {
            return new SegmentCount('GSM-7', $units, 1, self::GSM_SINGLE - $units, self::GSM_SINGLE);
        }

        return $this->pack($costs, self::GSM_MULTI, 'GSM-7', $units);
    }

    private function countUcs2(string $body): SegmentCount
    {
        $costs = [];

        foreach ($this->characters($body) as $char) {
            // UTF-16 code units: everything in the BMP (all Bengali) is one, astral planes (emoji) are a surrogate pair.
            $costs[] = mb_ord($char, 'UTF-8') > 0xFFFF ? 2 : 1;
        }

        $units = array_sum($costs);

        if ($units <= self::UCS2_SINGLE) {
            return new SegmentCount('UCS-2', $units, 1, self::UCS2_SINGLE - $units, self::UCS2_SINGLE);
        }

        return $this->pack($costs, self::UCS2_MULTI, 'UCS-2', $units);
    }

    /**
     * Fill parts of $perPart units without ever splitting a 2-unit character (a GSM escape pair or a surrogate
     * pair): a character that does not fit starts the next part, leaving one unit of the previous part unused.
     *
     * @param  array<int, int>  $costs
     */
    private function pack(array $costs, int $perPart, string $encoding, int $units): SegmentCount
    {
        $segments = 1;
        $inPart = 0;

        foreach ($costs as $cost) {
            if ($inPart + $cost > $perPart) {
                $segments++;
                $inPart = 0;
            }

            $inPart += $cost;
        }

        return new SegmentCount($encoding, $units, $segments, $perPart - $inPart, $perPart);
    }

    /** @return array<int, string> */
    private function characters(string $body): array
    {
        $chars = preg_split('//u', $body, -1, PREG_SPLIT_NO_EMPTY);

        return $chars === false ? [] : $chars;
    }
}
