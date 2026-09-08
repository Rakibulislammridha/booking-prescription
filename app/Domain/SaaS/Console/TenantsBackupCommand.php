<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Actions\Tenants\BackupTenant;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * `tenants:backup` — the daily per-tenant dump (ARCHITECTURE §8.8, scheduled per Appendix C item 27).
 *
 * It lives in `App\Domain\SaaS\Console` rather than `app/Tenancy/Console` (where ARCHITECTURE §4.2 sketched it)
 * because `app/Tenancy/**` is the foundation's tree and backups are this module's responsibility. The COMMAND
 * NAME is the documented one, which is what the scheduler, the runbooks and Appendix C actually refer to.
 */
final class TenantsBackupCommand extends Command
{
    protected $signature = 'tenants:backup {tenant?* : Slugs or ids; default is every provisioned tenant}
                            {--manual : Record the dump as a manual backup rather than a daily one}
                            {--prune : Also delete expired completed backups}';

    protected $description = 'pg_dump every tenant schema to the backups disk and register it in public.tenant_backups';

    public function handle(BackupTenant $backup): int
    {
        if (Tenancy::check()) {
            $this->components->error('tenants:backup is central work and must not run inside a tenant.');

            return self::FAILURE;
        }

        /** @var array<int, string> $only */
        $only = (array) $this->argument('tenant');
        $type = $this->option('manual') ? BackupType::Manual : BackupType::Daily;
        $ok = 0;
        $failed = 0;

        $tenants = Tenant::query()
            ->when($only !== [], fn ($q) => $q->where(fn ($w) => $w->whereIn('slug', $only)->orWhereIn('id', array_filter($only, 'is_numeric'))))
            ->whereNotNull('provisioned_at')
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            try {
                $row = $backup->handle($tenant, $type);
                // The mode is printed, not just stored: an operator watching the daily run must be able to see the
                // moment a deployment starts writing clinics' records to the bucket in the clear.
                $this->components->twoColumnDetail(
                    $tenant->slug,
                    number_format((int) $row->getAttribute('size_bytes')).' bytes · '.$row->encryption->value,
                );
                $ok++;
            } catch (Throwable $e) {
                $this->components->error($tenant->slug.': '.$e->getMessage());
                $failed++;
            }
        }

        if ($this->option('prune')) {
            $this->prune();
        }

        $this->components->info("{$ok} backup(s) written, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function prune(): void
    {
        $expired = TenantBackup::query()
            ->where('status', BackupStatus::Completed->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', CarbonImmutable::now())
            ->get();

        foreach ($expired as $row) {
            if ($row->storage_path !== null) {
                Storage::disk($row->storage_disk)->delete($row->storage_path);
            }

            $row->delete();
        }

        $this->components->twoColumnDetail('pruned', (string) $expired->count());
    }
}
