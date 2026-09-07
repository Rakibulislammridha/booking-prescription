<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Services\ClinicUploads;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use Illuminate\Http\UploadedFile;

/**
 * `doctors.photo_path` is not part of DoctorData (the doctor form posts JSON, the photo posts multipart), so the
 * upload is its own one-line use case rather than a file field bolted onto UpdateDoctor.
 */
final class UpdateDoctorPhoto
{
    public function __construct(private readonly ClinicUploads $uploads) {}

    public function handle(Doctor $doctor, ?UploadedFile $photo, Actor $actor): Doctor
    {
        $doctor->photo_path = $photo === null ? null : $this->uploads->doctorPhoto($doctor->public_id, $photo);
        $doctor->save();

        return $doctor;
    }
}
