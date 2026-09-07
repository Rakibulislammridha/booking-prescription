<?php

declare(strict_types=1);

namespace App\Support\Storage;

use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;

/**
 * Every tenant object key is tenants/{id}/{rel} (ARCHITECTURE §8.7). Direct disk paths fail code review.
 */
final class TenantPath
{
    public static function for(string $relative): string
    {
        $id = Tenancy::id() ?? throw new TenancyNotInitialized(self::class);

        return "tenants/{$id}/".ltrim($relative, '/');
    }
}
