<?php

declare(strict_types=1);

namespace App\Domain\Reports\Export;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportScope;
use App\Domain\Reports\Data\ReportTable;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ExportStatus;
use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Jobs\GenerateReportExport;
use App\Domain\Reports\Services\ReportData;
use App\Models\Tenant\ReportExport;
use App\Models\Tenant\User;
use App\Support\Storage\TenantPath;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Produces an export, and decides whether the user waits for it.
 *
 * SIZE. Below `SYNC_ROW_LIMIT` rows the file is written into the response and the browser starts downloading
 * immediately — which is every ordinary report, because the aggregates are already grouped. Above it the work
 * goes to Horizon's `reports` queue (ARCHITECTURE §4.6) and the caller gets a `ReportExport` row to come back
 * to; nothing about a clinic's month should be able to hold an HTTP worker for a minute.
 *
 * AUDIT. Every export writes one `audit_logs` row (ARCHITECTURE §8.1, CONVENTIONS §5 "every export … calls the
 * matching AuditAction"). The auditable is the EXPORTING USER, following Billing's precedent, because an export
 * is not one record and `auditable_id` must point at something real; the report, format, range and filters go in
 * `context`, so "who took the clinic's revenue home on a USB stick" is answerable.
 *
 * DISK. Queued files live on the `uploads` disk under `TenantPath::for()` (ARCHITECTURE §8.7) — never a raw
 * path — and carry an expiry, because a report file is a copy of clinical and financial data and copies should
 * not accumulate for ever.
 */
final class ReportExporter
{
    /** Rows a browser will happily wait for; past it the `reports` queue does the work. */
    public const SYNC_ROW_LIMIT = 5000;

    public const RETENTION_DAYS = 7;

    public const DISK = 'uploads';

    public function __construct(
        private readonly ReportData $data,
        private readonly ReportTableBuilder $builder,
        private readonly CsvWriter $csv,
        private readonly XlsxWriter $xlsx,
        private readonly PdfWriter $pdf,
        private readonly AuditRecorder $audit,
    ) {}

    /** The table for a report, built from exactly the payload the page would have shown. */
    public function table(ReportKind $kind, ReportFilters $filters, ReportScope $scope): ReportTable
    {
        $payload = $this->data->for($kind, $filters, $scope);

        return $this->builder->build($kind, $payload['data'], $filters);
    }

    /** True when this export must not be produced inside the request. */
    public function isLarge(ReportTable $table): bool
    {
        return ($table->knownRowCount ?? 0) > self::SYNC_ROW_LIMIT;
    }

    /** Stream (or render) the file into the response. */
    public function respond(ReportKind $kind, ExportFormat $format, ReportTable $table, ReportFilters $filters, ?User $user): StreamedResponse|Response
    {
        $this->recordAudit($kind, $format, $filters, $user, $table->knownRowCount, queued: false);
        $filename = $this->filename($kind, $format, $filters);

        return match ($format) {
            ExportFormat::Csv => $this->csv->response($table, $filename),
            ExportFormat::Xlsx => $this->streamWorkbook($table, $filename),
            ExportFormat::Pdf => $this->pdfResponse($table, $filename),
        };
    }

    /** Hand the work to Horizon's `reports` queue and return the receipt row. */
    public function queue(ReportKind $kind, ExportFormat $format, ReportFilters $filters, ReportScope $scope, User $user, ReportTable $table): ReportExport
    {
        $export = new ReportExport;
        $export->fill([
            'user_id' => $user->id,
            'report' => $kind->value,
            'format' => $format->value,
            'status' => ExportStatus::Pending->value,
            'filters' => $filters->toArray(),
            'title' => mb_substr($table->title, 0, 160),
            'expires_at' => now()->addDays(self::RETENTION_DAYS),
        ]);
        $export->save();

        $this->recordAudit($kind, $format, $filters, $user, $table->knownRowCount, queued: true);

        GenerateReportExport::dispatch($export->id, $scope->toArray());

        return $export;
    }

    /** Called by the job: write the file to the tenant's disk and mark the row ready. */
    public function fulfil(ReportExport $export, ReportTable $table): void
    {
        $format = $export->format;
        $relative = TenantPath::for(sprintf('reports/%s/%s.%s', $export->report, $export->public_id, $format->extension()));
        $temp = tempnam(sys_get_temp_dir(), 'bp-export-');

        if ($temp === false) {
            throw new RuntimeException('Could not allocate a temporary export file.');
        }

        try {
            $rows = match ($format) {
                ExportFormat::Csv => $this->csv->toFile($table, $temp),
                ExportFormat::Xlsx => $this->xlsx->toFile($table, $temp)['rows'],
                ExportFormat::Pdf => $this->writePdf($table, $temp),
            };

            $handle = fopen($temp, 'rb');

            if ($handle === false) {
                throw new RuntimeException('Could not read back the generated export.');
            }

            Storage::disk(self::DISK)->put($relative, $handle);

            if (is_resource($handle)) {
                fclose($handle);
            }

            $export->forceFill([
                'status' => ExportStatus::Ready->value,
                'file_path' => $relative,
                'file_size' => (int) (filesize($temp) ?: 0),
                'row_count' => $rows,
                'completed_at' => now(),
            ])->save();
        } finally {
            @unlink($temp);
        }
    }

    public function filename(ReportKind $kind, ExportFormat $format, ReportFilters $filters): string
    {
        return sprintf('%s-%s-%s.%s', $kind->value, $filters->fromDate(), $filters->toDate(), $format->extension());
    }

    /**
     * The workbook has to exist as a file before it can be zipped, so it is built into a temp file and streamed
     * out of it — still bounded memory, and the temp file dies with the response.
     */
    private function streamWorkbook(ReportTable $table, string $filename): StreamedResponse
    {
        $temp = tempnam(sys_get_temp_dir(), 'bp-xlsx-');

        if ($temp === false) {
            throw new RuntimeException('Could not allocate a temporary workbook.');
        }

        $this->xlsx->toFile($table, $temp);

        return response()->stream(function () use ($temp): void {
            $handle = fopen($temp, 'rb');

            if ($handle !== false) {
                while (! feof($handle)) {
                    echo (string) fread($handle, 65536);
                    flush();
                }

                fclose($handle);
            }

            @unlink($temp);
        }, 200, [
            'Content-Type' => ExportFormat::Xlsx->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /**
     * Without Chrome there is no PDF (DomPDF is banned, BRIEF §2). Rather than a 500, the printable HTML is
     * returned with the same content — the browser's own "print to PDF" produces the identical document, and
     * Bangla still shapes correctly because it is the same engine.
     */
    private function pdfResponse(ReportTable $table, string $filename): Response
    {
        if (! $this->pdf->available()) {
            return response($this->pdf->html($table), 200, [
                'Content-Type' => 'text/html; charset=UTF-8',
                'Cache-Control' => 'no-store, private',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        return response($this->pdf->bytes($table), 200, [
            'Content-Type' => ExportFormat::Pdf->contentType(),
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function writePdf(ReportTable $table, string $path): int
    {
        $rows = min($table->knownRowCount ?? 0, PdfWriter::MAX_ROWS);

        if ($this->pdf->available()) {
            $this->pdf->toFile($table, $path);
        } else {
            file_put_contents($path, $this->pdf->html($table));
        }

        return $rows;
    }

    private function recordAudit(ReportKind $kind, ExportFormat $format, ReportFilters $filters, ?User $user, ?int $rows, bool $queued): void
    {
        if ($user === null) {
            return;
        }

        $this->audit->record(AuditAction::Export, $user, null, null, [
            'report' => $kind->value,
            'format' => $format->value,
            'from' => $filters->fromDate(),
            'to' => $filters->toDate(),
            'filters' => $filters->toArray(),
            'rows' => $rows,
            'queued' => $queued,
        ]);
    }
}
