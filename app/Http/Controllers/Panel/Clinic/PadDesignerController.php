<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Actions\UpdateDoctorPadSettings;
use App\Domain\Clinic\Data\PadSettingsData;
use App\Domain\Clinic\Enums\PadOrientation;
use App\Domain\Clinic\Enums\PadPaperSize;
use App\Domain\Clinic\Enums\TokenSlipTemplate;
use App\Domain\Clinic\Services\ClinicUploads;
use App\Domain\Clinic\Services\PadSampleText;
use App\Domain\Clinic\Services\PadTestSheet;
use App\Domain\Patients\Exceptions\OcrEngineUnavailable;
use App\Domain\Patients\Exceptions\OcrFailed;
use App\Domain\Prescription\Data\Letterhead;
use App\Domain\Prescription\Render\PadGeometry;
use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\RemovePadAssetRequest;
use App\Http\Requests\Panel\Clinic\UpdatePadSettingsRequest;
use App\Http\Requests\Panel\Clinic\UploadPadAssetRequest;
use App\Http\Requests\Panel\Clinic\UploadPadSampleRequest;
use App\Http\Resources\Clinic\DoctorResource;
use App\Http\Resources\Clinic\PadSettingResource;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BRIEF §5.A — the prescription pad designer. Controls on the left, a live preview at true proportion on the right.
 *
 * The preview is drawn client-side (`resources/js/panel/lib/clinic/padGeometry.ts`) from the same numbers
 * `App\Domain\Prescription\Render\PadGeometry` derives, and this controller hands the page those bounds
 * (`limits`) straight off the renderer's own clamps so the two can only drift if someone edits both. The button
 * that settles it either way is "print a test page": it renders the REAL print route through
 * `PrescriptionRenderer` with the doctor's live pad row, which is what a doctor feeds a physical preprinted pad
 * through to check the blank band lines up.
 */
final class PadDesignerController extends Controller
{
    /** The sample is a tracing guide, never print output: only these render as an underlay in the browser. */
    private const SAMPLE_KINDS = ['png' => 'image', 'jpg' => 'image', 'jpeg' => 'image', 'webp' => 'image', 'pdf' => 'pdf'];

    public function __construct(
        private readonly ClinicUploads $uploads,
        private readonly PadSampleText $sampleText,
        private readonly AuditRecorder $audit,
    ) {}

    public function edit(Doctor $doctor): InertiaResponse
    {
        $this->authorize('designPad', $doctor);
        $pad = $this->pad($doctor);
        $doctor->loadMissing(['profile', 'specialties']);

        return Inertia::render('Clinic/Doctors/Pad', [
            'doctor' => (new DoctorResource($doctor))->resolve(),
            'pad' => (new PadSettingResource($pad))->resolve(),
            'defaults' => (new PadSettingResource(new DoctorPadSetting(DoctorPadSetting::defaults())))->resolve(),
            'options' => [
                'paper_sizes' => array_map(fn (PadPaperSize $p) => $p->value, PadPaperSize::cases()),
                'orientations' => array_map(fn (PadOrientation $o) => $o->value, PadOrientation::cases()),
                'token_slip_templates' => array_map(fn (TokenSlipTemplate $t) => $t->value, TokenSlipTemplate::cases()),
                'languages' => ['bn', 'en', 'both'],
                'sections' => PadGeometry::SECTIONS,
            ],
            // Exactly the clamps PadGeometry applies when it renders (PRESCRIPTION.md §7.2). The designer refuses
            // values the renderer would silently pull back, so what is previewed is what is printed.
            'limits' => PadGeometry::LIMITS,
            'assets' => [
                'logo_url' => $this->assetUrl($doctor, 'logo'),
                'signature_url' => $this->assetUrl($doctor, 'signature'),
                'sample_url' => $this->assetUrl($doctor, 'sample'),
                'sample_kind' => $this->sampleKind($pad->sample_path),
            ],
            // "Reset to my profile": the letterhead the doctor's own name, degrees, BMDC number and clinic make.
            // It is the SAME `Letterhead::defaults()` the renderer falls back to for an undesigned pad, so the
            // designer's starting point is literally what that doctor prints today.
            'letterhead_defaults' => Letterhead::defaults($doctor, Tenancy::current())->toArray(),
            // Whether "read text from the sample" can actually do anything here. Absent configuration it is
            // false with a reason, and the designer says so rather than offering a button that does nothing.
            'ocr' => [
                'available' => $this->sampleText->isAvailable($pad->sample_path),
                'reason' => $this->sampleText->reason($pad->sample_path),
            ],
            // One-shot: the lines the last "read text" produced, offered as a per-line prefill.
            'sample_lines' => array_values(array_filter(
                (array) session('pad.sample_lines', []),
                'is_string',
            )),
        ]);
    }

    public function update(UpdatePadSettingsRequest $request, Doctor $doctor, UpdateDoctorPadSettings $update): RedirectResponse
    {
        $update->handle($doctor, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.doctors.pad.edit', ['doctor' => $doctor->public_id])
            ->with('flash.success', __('clinic.pad.flash.saved'));
    }

    /** Logo / signature upload; the stored path is written onto the pad row through the same action as every field. */
    public function asset(UploadPadAssetRequest $request, Doctor $doctor, UpdateDoctorPadSettings $update): RedirectResponse
    {
        $file = $request->file('file');
        abort_if(! $file instanceof UploadedFile, 422);

        $kind = $request->kind();
        $path = $this->uploads->padAsset($doctor->public_id, $kind, $file);
        $update->handle($doctor, PadSettingsData::fromArray([$kind === 'logo' ? 'logo_path' : 'signature_path' => $path]), Actor::fromRequest($request));

        return back()->with('flash.success', __($kind === 'logo' ? 'clinic.pad.flash.logo_saved' : 'clinic.pad.flash.signature_saved'));
    }

    /** Removes the stored logo/signature path (the object itself is kept; older snapshots may still inline it). */
    public function removeAsset(RemovePadAssetRequest $request, Doctor $doctor, UpdateDoctorPadSettings $update): RedirectResponse
    {
        $kind = $request->kind();
        $update->handle($doctor, PadSettingsData::fromArray([$kind === 'logo' ? 'logo_path' : 'signature_path' => null]), Actor::fromRequest($request));

        return back()->with('flash.success', __($kind === 'logo' ? 'clinic.pad.flash.logo_removed' : 'clinic.pad.flash.signature_removed'));
    }

    /**
     * The sample pad: a photo or PDF of the stationery the clinic already prints, stored on the private uploads
     * disk and drawn UNDER the live preview at true scale. It is a tracing guide — the designer says so in as
     * many words — and it is never printed, never inlined into a snapshot and never read automatically.
     */
    public function sample(UploadPadSampleRequest $request, Doctor $doctor, UpdateDoctorPadSettings $update): RedirectResponse
    {
        $file = $request->sample();
        $path = $this->uploads->padSample($doctor->public_id, $file);
        $pad = $update->handle($doctor, PadSettingsData::fromArray(['sample_path' => $path]), Actor::fromRequest($request));

        $this->audit->record(AuditAction::Update, $pad, null, ['sample_path' => $path], [
            'doctor_public_id' => $doctor->public_id,
            'mime_type' => $file->getClientMimeType(),
            'bytes' => $file->getSize(),
        ]);

        return back()->with('flash.success', __('clinic.pad.flash.sample_saved'));
    }

    /** Clears the underlay and deletes the object: unlike a logo, no snapshot has ever referenced it. */
    public function removeSample(Request $request, Doctor $doctor, UpdateDoctorPadSettings $update): RedirectResponse
    {
        $this->authorize('designPad', $doctor);
        $pad = $this->pad($doctor);
        $path = $pad->sample_path;

        if ($path !== null) {
            Storage::disk($this->uploads->uploadsDisk())->delete($path);
        }

        $pad = $update->handle($doctor, PadSettingsData::fromArray(['sample_path' => null]), Actor::fromRequest($request));
        $this->audit->record(AuditAction::Delete, $pad, ['sample_path' => $path], null, ['doctor_public_id' => $doctor->public_id]);

        return back()->with('flash.success', __('clinic.pad.flash.sample_removed'));
    }

    /**
     * "Read text from the sample" — the one automatic step that is honest (BRIEF §5.A). It offers the LINES it
     * found, per line, for the doctor to accept; it never rebuilds a design, because a wrong guess at colours and
     * positions costs more to undo than to type. With no engine configured the route is not offered at all.
     */
    public function readSample(Request $request, Doctor $doctor): RedirectResponse
    {
        $this->authorize('designPad', $doctor);
        $pad = $this->pad($doctor);
        $path = $pad->sample_path;

        if ($path === null || ! $this->sampleText->isAvailable($path)) {
            return back()->with('flash.error', __('clinic.pad.underlay.read_unavailable'));
        }

        try {
            $lines = $this->sampleText->lines($this->uploads->uploadsDisk(), $path);
        } catch (OcrFailed|OcrEngineUnavailable) {
            return back()->with('flash.error', __('clinic.pad.underlay.read_failed'));
        }

        $this->audit->record(AuditAction::View, $pad, null, null, ['doctor_public_id' => $doctor->public_id, 'sample_lines' => count($lines)]);

        return back()
            ->with('pad.sample_lines', $lines)
            ->with($lines === [] ? 'flash.warning' : 'flash.success', __($lines === [] ? 'clinic.pad.underlay.read_empty' : 'clinic.pad.underlay.read_done', ['count' => count($lines)]));
    }

    /**
     * The alignment sheet. Same renderer, same Blade tree, same PadGeometry as a real prescription — only the
     * content is sample data, and it carries the DRAFT watermark so a test page can never pass for a prescription.
     */
    public function testPrint(Doctor $doctor, PadTestSheet $sheet, PrescriptionRenderer $renderer): Response
    {
        $this->authorize('designPad', $doctor);
        $pad = $this->pad($doctor);

        $html = $renderer->render($sheet->snapshot($doctor, $pad), $sheet->options($pad));

        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** Serves a pad asset from the private `uploads` disk through the panel session. */
    public function assetFile(Doctor $doctor, string $kind): StreamedResponse
    {
        $this->authorize('designPad', $doctor);
        abort_unless(in_array($kind, ['logo', 'signature', 'sample'], true), 404);

        $pad = $this->pad($doctor);
        $path = $this->assetPath($pad, $kind);
        $disk = Storage::disk($this->uploads->uploadsDisk());

        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Robots-Tag' => 'noindex, nofollow']);
    }

    private function pad(Doctor $doctor): DoctorPadSetting
    {
        /** @var DoctorPadSetting $pad */
        $pad = $doctor->padSetting ?? DoctorPadSetting::query()->firstOrCreate(['doctor_id' => $doctor->id], DoctorPadSetting::defaults());

        return $pad;
    }

    private function assetUrl(Doctor $doctor, string $kind): ?string
    {
        $path = $this->assetPath($this->pad($doctor), $kind);

        return $path === null ? null : route('panel.clinic.doctors.pad.asset.show', ['doctor' => $doctor->public_id, 'kind' => $kind]);
    }

    private function assetPath(DoctorPadSetting $pad, string $kind): ?string
    {
        return match ($kind) {
            'logo' => $pad->logo_path,
            'sample' => $pad->sample_path,
            default => $pad->signature_path,
        };
    }

    /** 'image' | 'pdf' | null — the designer draws an image underlay one way and a PDF's first page another. */
    private function sampleKind(?string $path): ?string
    {
        return $path === null ? null : (self::SAMPLE_KINDS[strtolower(pathinfo($path, PATHINFO_EXTENSION))] ?? null);
    }
}
