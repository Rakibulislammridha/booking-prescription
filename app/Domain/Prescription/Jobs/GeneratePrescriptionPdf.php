<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Jobs;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Prescription\Events\PdfReady;
use App\Domain\Prescription\Render\PdfRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use App\Domain\Prescription\Services\PdfStorage;
use App\Models\Tenant\Prescription;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * PRESCRIPTION.md §7.5 — renders the issued prescription to PDF on Horizon's `pdf` queue and stores it on the
 * `pdfs` disk. It re-renders the FROZEN snapshot, so a regeneration years later produces the same paper even if
 * the catalog, the doctor's pad or the clinic's letterhead have all changed since.
 *
 * Unique by prescription id (the writer may issue-and-print while the listener is already queued) and idempotent:
 * an existing file is left alone unless the caller forces a regeneration.
 */
final class GeneratePrescriptionPdf implements ShouldBeUnique, ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public readonly int $prescriptionId, public readonly bool $force = false)
    {
        $this->onQueue('pdf');

        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    /**
     * One pending render per prescription — the writer can issue-and-print while the issue listener has already
     * queued one. `force` is part of the key on purpose: a regeneration asked for explicitly must not be swallowed
     * by an ordinary render that is already waiting.
     */
    public function uniqueId(): string
    {
        return ($this->tenantId ?? 0).':'.$this->prescriptionId.':'.($this->force ? 'force' : 'auto');
    }

    public function handle(PdfRenderer $renderer, PdfStorage $storage, AuditRecorder $audit): void
    {
        $rx = Prescription::query()->with('patient')->find($this->prescriptionId);

        if ($rx === null || $rx->snapshot === null || $rx->isDraft()) {
            return;
        }

        if (! $this->force && $storage->exists($rx)) {
            return;
        }

        if (! $renderer->available()) {
            // Chrome missing is an environment fault, not a data fault: log it once and stop, rather than
            // burning three attempts and a failed-jobs row per prescription.
            Log::warning('prescription.pdf.chrome_missing', ['prescription_id' => $rx->id, 'chrome_path' => PdfRenderer::chromePath()]);

            return;
        }

        $options = RenderOptions::fromPad($rx->snapshot->pad(), purpose: 'pdf', language: $rx->language->value)
            ->withWatermark($rx->status->value === 'voided' ? 'VOID' : null);

        $bytes = $renderer->pdf($rx->snapshot, $options);
        $path = $storage->put($rx, $bytes);

        // pdf_path / pdf_generated_at are on the permitted post-issue column list (§6.5); forceFill keeps the
        // immutability guard satisfied without reopening the row.
        $rx->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();

        $audit->record(AuditAction::Update, $rx, null, ['pdf_path' => $path], ['event' => 'pdf_generated', 'path' => $path, 'bytes' => strlen($bytes)]);

        PdfReady::dispatch($rx->id, $path, $rx->public_id);
    }
}
