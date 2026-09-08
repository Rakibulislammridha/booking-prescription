<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Actions\Tenants\RestoreTenantBackup;
use App\Models\Central\TenantBackup;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;

/**
 * `tenants:restore {backup} --force` — restore a registered dump into a scratch schema, verify it, then swap.
 *
 * `--force` is required because the swap displaces the live schema, and a restore run by accident on a working
 * clinic is the most expensive mistake in this module.
 */
final class TenantsRestoreCommand extends Command
{
    protected $signature = 'tenants:restore {backup : public.tenant_backups id} {--force : Required; the swap displaces the live schema}';

    protected $description = 'Restore a tenant backup into a scratch schema and swap it with the live one';

    public function handle(RestoreTenantBackup $restore): int
    {
        if (Tenancy::check()) {
            $this->components->error('tenants:restore is central work and must not run inside a tenant.');

            return self::FAILURE;
        }

        if (! $this->option('force')) {
            $this->components->error('Refusing to restore without --force: this replaces the live tenant schema.');

            return self::FAILURE;
        }

        $backup = TenantBackup::query()->with('tenant')->find((int) $this->argument('backup'));

        if ($backup === null) {
            $this->components->error('No such backup.');

            return self::FAILURE;
        }

        $result = $restore->handle($backup);

        $this->components->twoColumnDetail('restored tables', (string) $result['tables']);
        $this->components->twoColumnDetail('displaced schema', $result['replaced']);
        $this->components->info('Restore complete. Drop the displaced schema once you have checked the data.');

        return self::SUCCESS;
    }
}
