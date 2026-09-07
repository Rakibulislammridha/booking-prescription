<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\DoctorFavourite;
use Illuminate\Foundation\Http\FormRequest;

/** PATCH /panel/doctors/me/favourites/{favourite} {is_pinned?, default_dose?, label?}. */
final class UpdateFavouriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $fav = $this->route('favourite');
        $doctorId = $this->user('web')?->doctor()->value('id');

        return $fav instanceof DoctorFavourite && $doctorId !== null && (int) $doctorId === $fav->doctor_id;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return ['is_pinned' => ['sometimes', 'boolean'], 'default_dose' => ['sometimes', 'array'], 'label' => ['sometimes', 'string', 'max:200']];
    }
}
