<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Models\Tenant\Branch;

/**
 * The staff user's active branch for the current request (SetActiveBranch middleware, SharedProps.branch).
 * Singleton, listed in config/octane.php 'flush'.
 */
final class ActiveBranch
{
    private ?Branch $branch = null;

    public function set(?Branch $branch): void
    {
        $this->branch = $branch;
    }

    public function current(): ?Branch
    {
        return $this->branch;
    }

    public function id(): ?int
    {
        return $this->branch?->id;
    }

    public function flush(): void
    {
        $this->branch = null;
    }
}
