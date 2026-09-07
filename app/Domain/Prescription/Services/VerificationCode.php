<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Models\Tenant\Prescription;

/** 12-character Crockford base32 code for /rx/{code}, unique per tenant (PRESCRIPTION.md §6.1). */
final class VerificationCode
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function generate(): string
    {
        do {
            $code = '';

            for ($i = 0; $i < 12; $i++) {
                $code .= self::ALPHABET[random_int(0, 31)];
            }
        } while (Prescription::query()->where('verification_code', $code)->exists());

        return $code;
    }

    public static function isValid(string $code): bool
    {
        return preg_match('/^[0-9A-HJKMNP-TV-Z]{12}$/', strtoupper($code)) === 1;
    }
}
