<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Str;

/**
 * `t{tenantId}-{ulid}` (SCHEMA §3.8: "never guessable"). The tenant prefix keeps two clinics' rooms apart on a
 * shared provider account; the ULID is 128 bits of entropy, which is what makes the patient's join link safe to
 * send over SMS even before the signature is checked.
 */
final class RoomName
{
    public static function generate(): string
    {
        $tenantId = Tenancy::check() ? (string) Tenancy::id() : '0';

        return 't'.$tenantId.'-'.strtolower((string) Str::ulid());
    }

    /** Route-parameter shape: `t<digits>-<26 crockford chars>`. Anything else is a 404 before any query runs. */
    public const PATTERN = 't[0-9]+-[0-9a-hjkmnp-tv-z]{26}';

    public static function looksValid(string $value): bool
    {
        return preg_match('/^'.self::PATTERN.'$/', $value) === 1;
    }
}
