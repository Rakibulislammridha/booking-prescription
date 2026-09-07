<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Services\HandwritingStorage;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Prescription;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Serves handwriting sheets (§4.12) and the annotation PNG (§4.11) from the PRIVATE `uploads` disk to authorised
 * staff. Without it the issued view can only list attachments as chips — the doctor cannot see what was actually
 * written, which defeats the point of handwriting mode being the adoption bridge.
 *
 * Three guards, in this order:
 *  1. the prescription is resolved inside the tenant (route-model binding on the tenant connection), so a key from
 *     another clinic cannot be reached at all;
 *  2. PrescriptionPolicy::view — the same gate as reading the prescription itself;
 *  3. the requested name is matched against the keys HandwritingStorage would have written for THIS row, so the
 *     path is derived, never taken from the URL (no traversal, no reading a neighbouring patient's folder).
 * Every hit writes an audit row (CONVENTIONS §5: every clinical-record read endpoint audits).
 */
final class AttachmentFileController extends Controller
{
    public function show(Request $request, Prescription $prescription, string $file, HandwritingStorage $files, PrescriptionAuditor $auditor): StreamedResponse
    {
        $this->authorize('view', $prescription);

        $path = $this->resolve($prescription, $file, $files);
        abort_if($path === null, 404);

        $disk = Storage::disk($files->disk());
        abort_unless($disk->exists($path), 404);

        $auditor->attachmentViewed($prescription, $file);

        return $disk->response($path, $file, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
            'X-Robots-Tag' => 'noindex, nofollow',
        ], $request->boolean('download') ? 'attachment' : 'inline');
    }

    /** The only two shapes that exist: `drawing.png` and `handwriting-{page}.png` (1–3, §4.12). */
    private function resolve(Prescription $prescription, string $file, HandwritingStorage $files): ?string
    {
        if ($file === 'drawing.png') {
            return $prescription->drawing_image_path;
        }

        if (preg_match('/^handwriting-([1-9]\d?)\.png$/', $file, $match) !== 1) {
            return null;
        }

        $candidate = $files->handwritingPath($prescription, (int) $match[1]);

        return in_array($candidate, $files->handwritingPages($prescription), true) ? $candidate : null;
    }
}
