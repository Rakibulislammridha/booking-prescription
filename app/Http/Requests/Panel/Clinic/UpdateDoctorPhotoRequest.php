<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateDoctorPhotoRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user('web')?->can('update', $doctor) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'photo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp', 'max:2048'],
            'clear' => ['sometimes', 'boolean'],
        ];
    }
}
