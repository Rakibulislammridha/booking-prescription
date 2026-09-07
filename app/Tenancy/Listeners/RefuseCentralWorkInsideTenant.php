<?php

declare(strict_types=1);

namespace App\Tenancy\Listeners;

use App\Tenancy\Database\TenantMigrator;
use App\Tenancy\Exceptions\CentralCommandInsideTenant;
use App\Tenancy\TenantContext;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Database\Events\MigrationsStarted;

/**
 * `php artisan migrate` (or migrate:fresh, db:wipe, db:seed …) run while a tenant is active would read the TENANT's
 * migrations table and build every central table inside tenant_<id>. Two layers refuse it: the console event for
 * any migrate or db: command, and MigrationsStarted for any migrator that is not the flagged TenantMigrator.
 */
final class RefuseCentralWorkInsideTenant
{
    /** Central-only artisan commands (prefix match). */
    public const BLOCKED_COMMANDS = ['migrate', 'db:', 'schema:'];

    public function __construct(
        private readonly TenantContext $context,
        private readonly TenantMigrator $migrator,
    ) {}

    public function onCommandStarting(CommandStarting $event): void
    {
        if ($this->context->tenant === null || ! self::isBlocked($event->command)) {
            return;
        }

        throw new CentralCommandInsideTenant($event->command, $this->context->tenant->id);
    }

    public function onMigrationsStarted(MigrationsStarted $event): void
    {
        if ($this->context->tenant === null || $this->migrator->isRunning()) {
            return;
        }

        throw new CentralCommandInsideTenant('migrator:'.$event->method, $this->context->tenant->id);
    }

    public static function isBlocked(string $command): bool
    {
        foreach (self::BLOCKED_COMMANDS as $prefix) {
            if ($command === rtrim($prefix, ':') || str_starts_with($command, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
