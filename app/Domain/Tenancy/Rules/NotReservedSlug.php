<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A tenant slug becomes a host label ({slug}.{central}); central hosts (super, www), service prefixes
 * (queue, book, display) and infrastructure names are refused (config('tenancy.reserved_slugs')).
 * Usable as a validation rule (onboarding, tenants:create) and directly via NotReservedSlug::isReserved().
 */
final class NotReservedSlug implements ValidationRule
{
    public const PATTERN = '/^[a-z0-9][a-z0-9-]{0,61}[a-z0-9]$/';   // 2–63 chars, a DNS label; public.tenants.tenants_slug_check agrees

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::isWellFormed($value)) {
            $fail('tenancy.slug_invalid')->translate();

            return;
        }

        if (self::isReserved($value)) {
            $fail('tenancy.slug_reserved')->translate();
        }
    }

    /** @return array<int, string> */
    public static function reserved(): array
    {
        return array_values(array_unique(array_merge(
            array_map('strval', (array) config('tenancy.reserved_slugs', [])),
            array_map('strval', (array) config('tenancy.service_prefixes', [])),
            ['super', 'www'],
        )));
    }

    public static function isReserved(string $slug): bool
    {
        return in_array(strtolower($slug), self::reserved(), true);
    }

    public static function isWellFormed(string $slug): bool
    {
        return preg_match(self::PATTERN, $slug) === 1;
    }
}
