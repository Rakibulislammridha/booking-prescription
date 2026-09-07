<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorFavourite;

/** PATCH /panel/doctors/me/favourites/{id} {is_pinned, default_dose?, label?}. */
final class UpdateFavourite
{
    public function __construct(private readonly DoctorLearningCache $cache) {}

    /** @param  array<string, mixed>  $data */
    public function handle(DoctorFavourite $favourite, array $data, Actor $actor): DoctorFavourite
    {
        $favourite->fill(array_intersect_key($data, array_flip(['is_pinned', 'default_dose', 'label'])));
        $favourite->save();
        $this->cache->refresh($favourite->doctor_id, $favourite->icd10_code !== null ? [$favourite->icd10_code] : []);

        return $favourite;
    }
}
