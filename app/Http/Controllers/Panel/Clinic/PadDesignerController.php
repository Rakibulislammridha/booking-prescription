<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\UpdateDoctorPadSettings;
use App\Domain\Clinic\Data\PadSettingsData;
use App\Domain\Clinic\Enums\PadOrientation;
use App\Domain\Clinic\Enums\PadPaperSize;
use App\Domain\Clinic\Enums\TokenSlipTemplate;
use App\Domain\Clinic\Services\ClinicUploads;
use App\Domain\Clinic\Services\PadTestSheet;
use App\Domain\Prescription\Render\PadGeometry;
use App\Domain\Prescription\Render\PrescriptionRenderer;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\RemovePadAssetRequest;
use App\Http\Requests\Panel\Clinic\UpdatePadSettingsRequest;
use App\Http\Requests\Panel\Clinic\UploadPadAssetRequest;
use App\Http\Resources\Clinic\DoctorResource;
use App\Http\Resources\Clinic\PadSettingResource;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use Illuminate\Http\RedirectResponse;
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
    public function __construct(private readonly ClinicUploads $uploads) {}

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
            ],
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
        abort_unless(in_array($kind, ['logo', 'signature'], true), 404);

        $pad = $this->pad($doctor);
        $path = $kind === 'logo' ? $pad->logo_path : $pad->signature_path;
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
        $pad = $this->pad($doctor);
        $path = $kind === 'logo' ? $pad->logo_path : $pad->signature_path;

        return $path === null ? null : route('panel.clinic.doctors.pad.asset.show', ['doctor' => $doctor->public_id, 'kind' => $kind]);
    }
}
