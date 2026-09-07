<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Patients\Exceptions\InvalidMobileNumber;

/**
 * Bangladeshi mobile numbers (SCHEMA §5.4): any of `017…`, `+88017…`, `88017…`, with spaces/dashes or Bangla
 * digits, normalises to E.164 `+8801XXXXXXXXX`; the `patients.mobile` CHECK enforces `^\+8801[3-9]\d{8}$`.
 */
final class MobileNumber
{
    public const E164_PATTERN = '/^\+8801[3-9]\d{8}$/';

    private const BN_DIGITS = ['০', '১', '২', '৩', '৪', '৫', '৬', '৭', '৮', '৯'];

    /** @throws InvalidMobileNumber */
    public static function normalize(string $input): string
    {
        $normalized = self::tryNormalize($input);

        return $normalized ?? throw new InvalidMobileNumber($input);
    }

    public static function tryNormalize(string $input): ?string
    {
        $digits = str_replace(self::BN_DIGITS, ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'], trim($input));
        $digits = preg_replace('/[\s\-().]/', '', $digits) ?? '';
        $digits = ltrim($digits, '+');

        if (preg_match('/^00?8801[3-9]\d{8}$/', $digits) === 1) {
            $digits = substr($digits, strpos($digits, '8801'));
        }

        $e164 = match (true) {
            preg_match('/^8801[3-9]\d{8}$/', $digits) === 1 => '+'.$digits,
            preg_match('/^01[3-9]\d{8}$/', $digits) === 1 => '+88'.$digits,
            default => null,
        };

        return $e164 !== null && preg_match(self::E164_PATTERN, $e164) === 1 ? $e164 : null;
    }

    public static function isValid(string $input): bool
    {
        return self::tryNormalize($input) !== null;
    }

    /** `+8801712345678` → `01712345678` (the form printed on slips and typed at the desk). */
    public static function toLocal(string $e164): string
    {
        return str_starts_with($e164, '+88') ? substr($e164, 3) : $e164;
    }

    /** `01712345678` → `017*****678` for confirmation screens. */
    public static function mask(string $mobile): string
    {
        $local = self::toLocal(self::tryNormalize($mobile) ?? $mobile);

        return strlen($local) === 11 ? substr($local, 0, 3).'*****'.substr($local, -3) : $local;
    }
}
