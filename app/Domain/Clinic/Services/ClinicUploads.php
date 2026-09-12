<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Support\Storage\TenantPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Every image the setup screens accept — pad logo, scanned signature, doctor photo, clinic branding logo — is written
 * under `tenants/{id}/…` through TenantPath (ARCHITECTURE §8.7); no screen ever builds a disk path itself.
 *
 * Two disks, deliberately: pad assets and doctor photos live on `uploads` (private, S3), because that is the disk
 * SnapshotBuilder::dataUri() reads when it inlines the logo and signature into `pad_snapshot` at issue. The clinic
 * branding logo lives on `public`, because HandleInertiaRequests turns it into `tenant.logo_url` for the public site.
 */
final class ClinicUploads
{
    public function uploadsDisk(): string
    {
        return (string) config('prescription.uploads_disk', 'uploads');
    }

    public function publicDisk(): string
    {
        return 'public';
    }

    /** tenants/{id}/doctors/{public_id}/pad/{logo|signature}-{ulid}.{ext} */
    public function padAsset(string $doctorPublicId, string $kind, UploadedFile $file): string
    {
        return $this->put($this->uploadsDisk(), "doctors/{$doctorPublicId}/pad/{$kind}-".Str::ulid(), $file);
    }

    /**
     * tenants/{id}/doctors/{public_id}/pad/sample-{ulid}.{ext} — the photo or PDF of the clinic's existing pad
     * that the designer draws under the live preview. Same private disk as the pad assets, and deliberately so:
     * it is a picture of a named doctor's stationery, not something a public URL should reach.
     */
    public function padSample(string $doctorPublicId, UploadedFile $file): string
    {
        return $this->put($this->uploadsDisk(), "doctors/{$doctorPublicId}/pad/sample-".Str::ulid(), $file);
    }

    /** tenants/{id}/doctors/{public_id}/photo-{ulid}.{ext} */
    public function doctorPhoto(string $doctorPublicId, UploadedFile $file): string
    {
        return $this->put($this->uploadsDisk(), "doctors/{$doctorPublicId}/photo-".Str::ulid(), $file);
    }

    /** tenants/{id}/branding/logo-{ulid}.{ext} on the public disk (the site renders it as an <img> src). */
    public function brandingLogo(UploadedFile $file): string
    {
        return $this->put($this->publicDisk(), 'branding/logo-'.Str::ulid(), $file);
    }

    private function put(string $disk, string $relativeWithoutExtension, UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: ($file->guessExtension() ?? 'png'));
        $path = TenantPath::for($relativeWithoutExtension.'.'.$extension);

        if (Storage::disk($disk)->putFileAs(dirname($path), $file, basename($path)) === false) {
            throw new RuntimeException("Could not store the uploaded file at [{$path}].");
        }

        return $path;
    }
}
