<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\SaaS\Actions\Tenants\DeleteTenant;
use App\Domain\SaaS\Exceptions\TenantHasUnsettledInvoices;
use App\Domain\SaaS\Jobs\ExportTenantDataJob;
use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Step one of deleting a clinic: the money guard is checked NOW — before an operator waits minutes for an archive
 * that step two would refuse anyway — and the churn export is queued through the same job the Data tab uses
 * (`ExportTenantDataJob` → `ExportTenantData`, BRIEF §5.N). Step two is `TenantController::destroy`.
 */
final class DeletionController extends Controller
{
    public function __invoke(Request $request, Tenant $tenant, DeleteTenant $delete): RedirectResponse
    {
        $unsettled = $delete->unsettledInvoices($tenant);

        if ($unsettled > 0) {
            throw new TenantHasUnsettledInvoices($unsettled);
        }

        ExportTenantDataJob::dispatch($tenant->id, (int) $request->user('super')?->getAuthIdentifier());

        return back()->with('flash.info', __('super.tenants.delete.flash.export_queued'));
    }
}
