<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\UpdateDoctorPhoto;
use App\Domain\Clinic\Services\ClinicUploads;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\UpdateDoctorPhotoRequest;
use App\Models\Tenant\Doctor;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The doctor's photo lives on the private `uploads` disk under TenantPath, so it is streamed back through the panel
 * session rather than exposed as a public URL — one tenant's staff must never be able to guess another's object key.
 */
final class DoctorPhotoController extends Controller
{
    public function __construct(private readonly ClinicUploads $uploads) {}

    public function store(UpdateDoctorPhotoRequest $request, Doctor $doctor, UpdateDoctorPhoto $update): RedirectResponse
    {
        $file = $request->file('photo');
        $update->handle($doctor, $file instanceof UploadedFile ? $file : null, Actor::fromRequest($request));

        return back()->with('flash.success', __($file instanceof UploadedFile ? 'clinic.doctors.flash.photo_saved' : 'clinic.doctors.flash.photo_removed'));
    }

    public function show(Doctor $doctor): StreamedResponse
    {
        $this->authorize('view', $doctor);
        $path = $doctor->photo_path;
        $disk = Storage::disk($this->uploads->uploadsDisk());

        abort_if($path === null || ! $disk->exists($path), 404);

        return $disk->response($path, null, ['Cache-Control' => 'private, max-age=300', 'X-Robots-Tag' => 'noindex, nofollow']);
    }
}
