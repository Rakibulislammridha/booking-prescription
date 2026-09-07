<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reports;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Reports\Export\ReportExporter;
use App\Http\Controllers\Controller;
use App\Models\Tenant\ReportExport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The queued exports a user has asked for, and the download of a finished one.
 *
 * A user may only see and download HIS OWN exports, not the clinic's: the file was produced under the
 * requester's scope, so handing it to someone else would hand them a report their own permissions might not
 * grant. The download writes a `download` audit entry — the original `export` entry recorded the request, this
 * one records the file actually leaving.
 */
final class ExportArchiveController extends Controller
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function index(Request $request): Response
    {
        Gate::authorize('reports.section');

        $exports = ReportExport::query()
            ->where('user_id', $request->user('web')->id ?? 0)
            ->orderByDesc('id')
            ->limit(25)
            ->get();

        return Inertia::render('Reports/Exports', [
            'exports' => $exports->map(fn (ReportExport $e): array => [
                'public_id' => $e->public_id,
                'report' => $e->report,
                'format' => $e->format->value,
                'status' => $e->status->value,
                'title' => $e->title,
                'row_count' => $e->row_count,
                'file_size' => $e->file_size,
                'error' => $e->error,
                'created_at' => $e->created_at?->toIso8601String(),
                'completed_at' => $e->completed_at?->toIso8601String(),
                'expires_at' => $e->expires_at?->toIso8601String(),
                'downloadable' => $e->isDownloadable(),
                'filters' => $e->filters,
            ])->all(),
        ]);
    }

    public function show(Request $request, ReportExport $export): StreamedResponse
    {
        Gate::authorize('reports.section');
        abort_unless($export->user_id === ($request->user('web')->id ?? 0), 403);
        abort_unless($export->isDownloadable() && $export->file_path !== null, 404);

        $this->audit->record(AuditAction::Download, $export, null, null, [
            'report' => $export->report,
            'format' => $export->format->value,
            'filters' => $export->filters,
        ]);

        return Storage::disk(ReportExporter::DISK)->download($export->file_path, $export->filename(), [
            'Content-Type' => $export->format->contentType(),
            'Cache-Control' => 'no-store, private',
        ]);
    }
}
