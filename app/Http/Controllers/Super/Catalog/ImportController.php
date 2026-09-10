<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Catalog\Enums\CatalogJobKind;
use App\Domain\Catalog\Enums\CatalogJobStatus;
use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Queries\CatalogImportsScreen;
use App\Domain\Catalog\Services\CatalogJobs;
use App\Domain\Catalog\Services\ImportBundleStore;
use App\Domain\SaaS\Services\CentralAudit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Catalog\QueueImportRequest;
use App\Http\Requests\Super\Catalog\UploadBundleRequest;
use App\Models\Catalog\CatalogVersion;
use App\Models\Central\CatalogJob;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Catalogue imports from the console (CATALOG.md §5): upload a bundle in the format `catalog:import` reads, run
 * it as a dry run (a report, nothing committed), then apply it — both as Horizon jobs, never inside a request.
 * The Imports page is also where the release history, the open issues and the two maintenance buttons live.
 */
final class ImportController extends Controller
{
    public function index(CatalogImportsScreen $screen): Response
    {
        return Inertia::render('Super/Catalog/Imports/Index', [
            'versions' => $screen->versions(),
            'jobs' => $screen->jobs(),
            'reindex' => $screen->latest(CatalogJobKind::Reindex),
            'reconcile' => $screen->latest(CatalogJobKind::Reconcile),
            'search' => $screen->searchStatus(),
            'open_issues' => $screen->openIssues(),
            'sources' => ImportSource::values(),
            'busy' => CatalogJob::query()->unfinished()->exists(),
        ]);
    }

    public function store(UploadBundleRequest $request, ImportBundleStore $store, CatalogJobs $jobs): RedirectResponse
    {
        $bundle = $store->store($request->uploads());
        $version = $request->validated('version');
        $releaseRef = $request->validated('release_ref');

        $job = $jobs->createImport($bundle, $request->source(), is_string($version) ? $version : null, is_string($releaseRef) ? $releaseRef : null, $request->boolean('full'), $request->admin()->id);

        return redirect()->route('super.catalog.imports.show', ['job' => $job->public_id])->with('flash.success', __('super.catalog.imports.flash.uploaded'));
    }

    public function show(Request $request, CatalogJob $job, CatalogImportsScreen $screen): Response
    {
        abort_unless($job->kind === CatalogJobKind::Import, 404);
        $job->load('requestedBy');

        return Inertia::render('Super/Catalog/Imports/Show', [
            'job' => $screen->job($job),
            // Applied rows only: a dry run rolled its issues back, so its samples travel inside the report instead.
            'issues' => $job->status === CatalogJobStatus::Succeeded && $job->mode === 'apply' && $job->catalog_version_id !== null ? $screen->issues($job->catalog_version_id) : [],
            'version' => $job->catalog_version_id === null ? null : collect($screen->versions(200))->firstWhere('id', $job->catalog_version_id),
            // A release with this bundle's checksum already exists (CATALOG.md §5.1): the buttons carry `force`
            // and say so, instead of the importer answering "already imported" to a click that meant "run it".
            'known_version' => $job->checksum === null ? null : CatalogVersion::query()->where('checksum_sha256', $job->checksum)
                ->where('id', '!=', $job->catalog_version_id ?? 0)->orderByDesc('id')->value('version'),
        ]);
    }

    public function dryRun(QueueImportRequest $request, CatalogJob $job, CatalogJobs $jobs): RedirectResponse
    {
        abort_unless($job->kind === CatalogJobKind::Import, 404);
        $jobs->queueImport($job, true, $request->admin()->id, $request->boolean('force'));

        return redirect()->route('super.catalog.imports.show', ['job' => $job->public_id])->with('flash.success', __('super.catalog.imports.flash.dry_run_queued'));
    }

    public function apply(QueueImportRequest $request, CatalogJob $job, CatalogJobs $jobs): RedirectResponse
    {
        abort_unless($job->kind === CatalogJobKind::Import, 404);
        $jobs->queueImport($job, false, $request->admin()->id, $request->boolean('force'));

        return redirect()->route('super.catalog.imports.show', ['job' => $job->public_id])->with('flash.success', __('super.catalog.imports.flash.apply_queued'));
    }

    /** Discard an uploaded bundle that will not be applied (the files go; the job row stays as history). */
    public function destroy(Request $request, CatalogJob $job, ImportBundleStore $store, CentralAudit $audit): RedirectResponse
    {
        abort_unless($job->kind === CatalogJobKind::Import, 404);
        abort_if(in_array($job->status, [CatalogJobStatus::Queued, CatalogJobStatus::Running], true), 409);

        if ($job->bundle_path !== null) {
            $store->delete($job->bundle_path);
        }

        $job->forceFill(['bundle_path' => null])->save();
        $audit->record(CentralAuditAction::Delete, null, $job, ['bundle_files' => $job->bundle_files], null);

        return redirect()->route('super.catalog.imports.index')->with('flash.success', __('super.catalog.imports.flash.discarded'));
    }
}
