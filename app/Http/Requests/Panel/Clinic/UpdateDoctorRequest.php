<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\DoctorData;
use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDoctorRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user('web')?->can('update', $doctor) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $doctor = $this->route('doctor');

        return StoreDoctorRequest::doctorRules($doctor instanceof Doctor ? $doctor->id : null);
    }

    public function toData(): DoctorData
    {
        return DoctorData::fromRequest($this);
    }
}
