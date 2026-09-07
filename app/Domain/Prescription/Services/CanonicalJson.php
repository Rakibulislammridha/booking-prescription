<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

/**
 * Canonical JSON for snapshot_sha256 (SCHEMA §3.4): keys sorted recursively, no whitespace, unicode and slashes
 * unescaped. Lists (0..n-1 keys) keep their order.
 */
final class CanonicalJson
{
    /** @param  array<string, mixed>  $data */
    public static function encode(array $data): string
    {
        return json_encode(self::sort($data), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param  array<string, mixed>  $data */
    public static function sha256(array $data): string
    {
        return hash('sha256', self::encode($data));
    }

    private static function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(self::sort(...), $value);
        }

        ksort($value, SORT_STRING);

        return array_map(self::sort(...), $value);
    }
}
