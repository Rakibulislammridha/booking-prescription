<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Catalog\Exceptions\CatalogWriteNotAllowed;
use Closure;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Auth;

/**
 * The only door to catalog writes (CATALOG.md §1.3, ARCHITECTURE.md §5.1). Container singleton listed in
 * config/octane.php 'flush'; no static state. While a run() closure executes, CatalogModel::getConnectionName()
 * returns 'catalog_admin' and the read-only model hooks let writes through. Callers: catalog:migrate/seed/import,
 * PromoteCustomBrand (guard `super`) and tests.
 */
final class CatalogWriteContext
{
    private int $depth = 0;

    public function __construct(
        private readonly Application $app,
        private readonly DatabaseManager $db,
    ) {}

    public function isOpen(): bool
    {
        return $this->depth > 0;
    }

    /**
     * Opens the admin context for the duration of $fn inside one catalog_admin transaction.
     * Only console commands and super admins may open it; anything else gets a 403 domain exception.
     *
     * @template T
     *
     * @param  Closure(): T  $fn
     * @return T
     */
    public function run(Closure $fn, ?string $reason = null): mixed
    {
        if (! $this->app->runningInConsole() && ! Auth::guard('super')->check()) {
            throw new CatalogWriteNotAllowed;
        }

        $this->depth++;

        try {
            return $this->db->connection('catalog_admin')->transaction($fn);
        } finally {
            $this->depth--;
        }
    }

    /** Octane 'flush' recreates the singleton; this exists for explicit resets in tests. */
    public function flush(): void
    {
        $this->depth = 0;
    }
}
