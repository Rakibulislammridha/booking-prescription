<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Tenants\RestoreTenantBackup;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Jobs\BackupTenantJob;
use App\Domain\SaaS\Jobs\ExportTenantDataJob;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Backups, restore and the churn export (BRIEF §5.N).
 *
 * Backup and export are QUEUED: both shell out to `pg_dump` or walk every table in a clinic's schema, which is
 * minutes of work for a busy hospital and has no business inside a web request. Restore is synchronous and
 * deliberately awkward — it displaces the live schema, so it is one explicit action a human takes while watching.
 *
 * The download is a signed, audited stream rather than a public URL: the archive is a complete copy of a clinic's
 * medical records, and a bucket link that leaked would be the worst incident this product could have.
 */
final class DataController extends Controller
{
    public function backup(Request $request, Tenant $tenant): RedirectResponse
    {
        BackupTenantJob::dispatch($tenant->id, (int) $request->user('super')?->getAuthIdentifier());

        return back()->with('flash.info', __('saas.tenants.flash.backup_queued'));
    }

    public function export(Request $request, Tenant $tenant): RedirectResponse
    {
        ExportTenantDataJob::dispatch($tenant->id, (int) $request->user('super')?->getAuthIdentifier());

        return back()->with('flash.info', __('saas.tenants.flash.export_queued'));
    }

    public function download(Request $request, Tenant $tenant, TenantBackup $backup, CentralAudit $audit): StreamedResponse
    {
        abort_unless($backup->tenant_id === $tenant->id, 404);
        abort_unless($backup->status === BackupStatus::Completed && $backup->storage_path !== null, 404);

        $audit->record(CentralAuditAction::Export, $tenant, $backup, null, ['downloaded' => $backup->storage_path]);

        $name = $tenant->slug.'-'.$backup->type->value.'-'.$backup->id.($backup->type === BackupType::Export ? '.zip' : '.dump');

        return Storage::disk($backup->storage_disk)->download($backup->storage_path, $name);
    }

    public function restore(Request $request, Tenant $tenant, TenantBackup $backup, RestoreTenantBackup $restore): RedirectResponse
    {
        abort_unless($backup->tenant_id === $tenant->id, 404);
        $request->validate(['confirm' => ['accepted']]);

        $result = $restore->handle($backup, (int) $request->user('super')?->getAuthIdentifier());

        return back()->with('flash.warning', __('saas.tenants.flash.restored', ['schema' => $result['replaced'], 'tables' => (string) $result['tables']]));
    }
}
