<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Jobs\GeneratePrescriptionPdf;
use App\Domain\Prescription\Render\PdfRenderer;
use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use App\Domain\Prescription\Services\PdfStorage;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Prescription\Services\SafetyChecker;
use App\Domain\Prescription\Services\SnapshotBuilder;
use App\Http\Controllers\Controller;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Browsershot\Exceptions\CouldNotTakeBrowsershot;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * PRESCRIPTION.md §7.3 / §7.5 / §7.6 — the panel's output surface.
 *
 *   GET  …/print?layout=&paper=&letterhead=&preprinted=&lang=   one-click browser print (auto window.print())
 *   GET  …/pharmacy                                             the dispensing sheet, A5 by default (§7.3)
 *   GET  …/pdf[?sync=1&download=1]                              stream the stored PDF, or 202 pending
 *   POST …/pdf/regenerate                                       re-render the SAME snapshot
 *
 * Every one of them renders the frozen snapshot through PrescriptionRenderer — the print, the PDF and the public
 * verification page are the same bytes of markup, which is the only way "the original remains printable forever"
 * (BRIEF §5.G.4) can be true. Draft preview (`?draft=1`) builds a transient snapshot with a DRAFT watermark and
 * is the single place where anything but the frozen document is rendered.
 */
final class PrintController extends Controller
{
    public function __construct(
        private readonly PrescriptionRenderer $renderer,
        private readonly PrescriptionAuditor $auditor,
        private readonly PdfStorage $storage,
    ) {}

    /** §7.6 one-click print. Issued prints bump printed_count / last_printed_at and audit `print`. */
    public function print(Request $request, Prescription $prescription): Response
    {
        [$snapshot, $options] = $this->document($request, $prescription, 'print');
        $html = $this->renderer->render($snapshot, $options);

        // Counted only once the sheet actually rendered — "printed 4 times" must mean four sheets, not four attempts.
        if (! $prescription->isDraft()) {
            $prescription->forceFill(['printed_count' => $prescription->printed_count + 1, 'last_printed_at' => now()])->save();
            $this->auditor->printed($prescription, $options->toArray());
        }

        return $this->html($html);
    }

    /** §7.3 pharmacy-friendly view: drug + quantity only, A5 unless the caller says otherwise. */
    public function pharmacy(Request $request, Prescription $prescription): Response
    {
        [$snapshot, $options] = $this->document($request, $prescription, 'print', defaults: ['layout' => 'pharmacy', 'paper' => $request->query('paper', 'A5')]);

        if (! $prescription->isDraft()) {
            $this->auditor->exported($prescription, 'html', 'pharmacy');
        }

        return $this->html($this->renderer->render($snapshot, $options));
    }

    /**
     * §7.5 — streams the stored PDF and audits `download`. `202 {status:"pending"}` while the queued job has not
     * finished (the writer tab is listening for PdfReady); `?sync=1` renders it inline for an immediate download,
     * which is what "Issue & download" needs when Horizon is not the thing the user is waiting on.
     */
    public function pdf(Request $request, Prescription $prescription, PdfRenderer $pdf): StreamedResponse|JsonResponse
    {
        $this->authorize('view', $prescription);
        abort_if($prescription->isDraft() || $prescription->snapshot === null, 404);

        if (! $this->storage->exists($prescription)) {
            if (! $request->boolean('sync')) {
                GeneratePrescriptionPdf::dispatch($prescription->id)->onQueue('pdf');

                return response()->json(['status' => 'pending'], 202);
            }

            if (! $pdf->available()) {
                return response()->json(['status' => 'unavailable', 'message' => __('prescriptions.pdf.unavailable')], 503);
            }

            // Deliberately NOT overridden by the query string: this render is stored as `pdf_path`, which is the
            // canonical file delivered by SMS/WhatsApp/email and served from /rx/{code}/pdf. It must always be the
            // pad's own geometry — a one-off "?paper=A5" must not become the patient's copy. Per-print variants
            // belong on the print route.
            $options = RenderOptions::fromPad($prescription->snapshot->pad(), purpose: 'pdf', language: $prescription->language->value)
                ->withWatermark($prescription->status->value === 'voided' ? 'VOID' : null);

            try {
                $bytes = $pdf->pdf($prescription->snapshot, $options);
            } catch (CouldNotTakeBrowsershot $e) {
                // A Chrome failure is an infrastructure fault, not a bad request: the doctor still has the print
                // route, and the queued job will retry. Answer plainly instead of a 500 stack trace.
                Log::error('prescription.pdf.render_failed', ['prescription_id' => $prescription->id, 'error' => $e->getMessage()]);

                return response()->json(['status' => 'failed', 'message' => __('prescriptions.pdf.unavailable')], 503);
            }

            $path = $this->storage->put($prescription, $bytes);
            $prescription->forceFill(['pdf_path' => $path, 'pdf_generated_at' => now()])->save();
        }

        $this->auditor->downloaded($prescription, ['path' => $prescription->pdf_path]);

        return Storage::disk($this->storage->disk())->download(
            (string) $prescription->pdf_path,
            $this->filename($prescription),
            ['Content-Type' => 'application/pdf', 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }

    /** §7.5 — regeneration is always allowed: it re-renders the same snapshot, so it cannot change the document. */
    public function regenerate(Prescription $prescription): JsonResponse
    {
        $this->authorize('view', $prescription);
        abort_if($prescription->isDraft() || $prescription->snapshot === null, 404);

        GeneratePrescriptionPdf::dispatch($prescription->id, force: true)->onQueue('pdf');

        return response()->json(['status' => 'pending'], 202);
    }

    /**
     * The snapshot to render plus the options to render it with. An issued row renders its frozen document; a
     * draft renders a transient preview (§7.1) and is watermarked DRAFT so a preview can never be mistaken for
     * a prescription.
     *
     * @param  array<string, mixed>  $defaults
     * @return array{0: PrescriptionSnapshot, 1: RenderOptions}
     */
    private function document(Request $request, Prescription $prescription, string $purpose, array $defaults = []): array
    {
        if ($prescription->isDraft()) {
            $this->authorize('write', $prescription);
            $prescription->load(['doctor.padSetting', 'items', 'investigations', 'advice', 'referrals', 'visit.serial', 'visit.latestVitals', 'patient.allergies', 'doctor.profile', 'doctor.specialties', 'branch']);
            $pad = $prescription->doctor->padSetting ?? new DoctorPadSetting(DoctorPadSetting::defaults());
            $snapshot = new PrescriptionSnapshot(app(SnapshotBuilder::class)->preview($prescription, $pad, app(SafetyChecker::class)->catalogVersion()));
            $watermark = 'DRAFT';
        } else {
            $this->authorize('view', $prescription);
            abort_if($prescription->snapshot === null, 404);
            $snapshot = $prescription->snapshot;
            $watermark = match ($prescription->status->value) {
                'voided' => 'VOID',
                'amended' => 'COPY',
                default => null,
            };
        }

        $options = RenderOptions::fromPad($snapshot->pad(), purpose: $purpose, language: $prescription->language->value)
            ->override($defaults + $request->query())
            ->withWatermark($watermark);

        return [$snapshot, $options];
    }

    private function html(string $html): Response
    {
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    private function filename(Prescription $rx): string
    {
        return 'prescription-'.($rx->verification_code ?? $rx->public_id).'-v'.$rx->version.'.pdf';
    }
}
