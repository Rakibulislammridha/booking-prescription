<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;

/**
 * Per-operation tenant state. Singleton, listed in config/octane.php 'flush'.
 */
final class TenantContext
{
    public ?Tenant $tenant = null;

    /** Service label stripped from the host (queue|book|display), if any. */
    public ?string $surfaceHint = null;

    public ?string $host = null;

    public ?CarbonImmutable $initialisedAt = null;

    public function flush(): void
    {
        $this->tenant = null;
        $this->surfaceHint = null;
        $this->host = null;
        $this->initialisedAt = null;
    }
}
