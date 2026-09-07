<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Prescription;

use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Prescription\Render\RenderOptions;
use App\Domain\Prescription\Services\PdfStorage;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Prescription\Services\VerificationCode;
use App\Http\Controllers\Controller;
use App\Http\Resources\Prescription\VerificationResource;
use App\Models\Tenant\Prescription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * GET /rx/{code} (site.prescription.verify, PRESCRIPTION.md §7.4): no auth, throttle:rx-verify, X-Robots-Tag
 * noindex. Anyone holding the link — the patient, a pharmacist, an insurer — sees the prescription exactly as it
 * was issued, rendered from the frozen snapshot and nothing else (I6), with a banner that answers the question
 * the page exists for: valid, superseded by version N, or voided.
 *
 * The code is the only credential, so it is 12 Crockford base32 characters (VerificationCode) — unguessable at
 * 30 requests/minute/IP — and the page shows the name, age and sex on the prescription and nothing more: the
 * mobile number was already masked into the snapshot at issue.
 */
final class VerificationController extends Controller
{
    public function show(Request $request, string $code, PrescriptionAuditor $auditor, PrescriptionRenderer $renderer): Response|JsonResponse
    {
        $rx = $this->find($code);
        $auditor->verifiedView($rx);

        $data = (new VerificationResource($rx))->resolve($request);
        $view = (string) config('prescription.views.verify', 'site.rx.show');

        if ($request->wantsJson() || ! view()->exists($view) || $rx->snapshot === null) {
            return response()->json($data)->header('X-Robots-Tag', 'noindex, nofollow');
        }

        $options = RenderOptions::fromPad($rx->snapshot->pad(), purpose: 'verify', language: $rx->language->value)
            ->override($request->query())
            ->withWatermark((string) $data['watermark']);

        return response()
            ->view($view, $renderer->data($rx->snapshot, $options) + ['verification' => $data + ['versions' => $this->chain($rx)]])
            ->header('X-Robots-Tag', 'noindex, nofollow')
            ->header('Cache-Control', 'no-store, private');
    }

    /** §7.4 "Download PDF serves the stored PDF for valid versions" — a voided or superseded copy is never handed out as a file. */
    public function pdf(string $code, PrescriptionAuditor $auditor, PdfStorage $storage): StreamedResponse
    {
        $rx = $this->find($code);
        abort_if($rx->voided_at !== null || $rx->status->value !== 'issued', 404);
        abort_unless($storage->exists($rx), 404);

        $auditor->downloaded($rx, ['surface' => 'verify']);

        return Storage::disk($storage->disk())->download(
            (string) $rx->pdf_path,
            'prescription-'.$rx->verification_code.'.pdf',
            ['Content-Type' => 'application/pdf', 'X-Robots-Tag' => 'noindex, nofollow'],
        );
    }

    private function find(string $code): Prescription
    {
        abort_unless(VerificationCode::isValid($code), 404);

        $rx = Prescription::query()->where('verification_code', strtoupper($code))->whereNotNull('issued_at')->first();
        abort_if($rx === null, 404);

        return $rx;
    }

    /**
     * Every version of the chain the holder may follow. An amended prescription must lead the reader to the
     * current one — a pharmacy dispensing the superseded version is the failure this page prevents.
     *
     * @return list<array{version: int, verification_code: string, issued_at: string|null, status: string}>
     */
    private function chain(Prescription $rx): array
    {
        return Prescription::versions($rx->root_prescription_id ?? $rx->id)
            ->filter(fn (Prescription $v) => $v->verification_code !== null)
            ->map(fn (Prescription $v) => ['version' => $v->version, 'verification_code' => (string) $v->verification_code, 'issued_at' => $v->issued_at?->toIso8601String(), 'status' => $v->status->value])
            ->values()->all();
    }
}
