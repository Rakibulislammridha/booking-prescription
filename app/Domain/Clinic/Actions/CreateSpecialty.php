<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\SpecialtyData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Specialty;

final class CreateSpecialty
{
    public function handle(SpecialtyData $data, Actor $actor): Specialty
    {
        return Specialty::query()->create($data->toAttributes());
    }
}
