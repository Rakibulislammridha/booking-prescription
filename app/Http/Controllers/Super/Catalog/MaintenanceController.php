<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Catalog\Services\CatalogJobs;
use App\Http\Controllers\Controller;
use App\Models\Central\SuperAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The two maintenance buttons on the Imports page: rebuild the Meilisearch indexes (`catalog:index-search
 * --fresh`) and run the reconciliation sweep now (`catalog:reconcile`). Both are queued through CatalogJobs —
 * a request never does the work — and both are refused while another job of the same kind is unfinished.
 */
final class MaintenanceController extends Controller
{
    public function reindex(Request $request, CatalogJobs $jobs): RedirectResponse
    {
        $jobs->queueReindex($this->adminId($request));

        return redirect()->route('super.catalog.imports.index')->with('flash.success', __('super.catalog.imports.flash.reindex_queued'));
    }

    public function reconcile(Request $request, CatalogJobs $jobs): RedirectResponse
    {
        $jobs->queueReconcile($this->adminId($request));

        return redirect()->route('super.catalog.imports.index')->with('flash.success', __('super.catalog.imports.flash.reconcile_queued'));
    }

    private function adminId(Request $request): int
    {
        $admin = $request->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin->id;
    }
}
