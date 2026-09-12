<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

/**
 * A photo or PDF of the clinic's EXISTING pad, uploaded so the designer can show it under the live preview as a
 * tracing guide (BRIEF §5.A). It is never printed, never inlined into `pad_snapshot` and never leaves the private
 * `uploads` disk — it is one image a doctor lines their blocks up against.
 *
 * A photographed pad is bigger than a logo (a phone camera shoots 4–6 MB), so the ceiling is 8 MB rather than the
 * 2 MB an asset gets; the type list is the same one the patient-document uploader accepts, minus the formats a
 * browser cannot draw.
 */
final class UploadPadSampleRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user('web')?->can('designPad', $doctor) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'mimes:png,jpg,jpeg,webp,pdf', 'mimetypes:image/png,image/jpeg,image/webp,application/pdf', 'max:8192'],
        ];
    }

    public function sample(): UploadedFile
    {
        $file = $this->file('file');
        abort_if(! $file instanceof UploadedFile, 422);

        return $file;
    }
}
