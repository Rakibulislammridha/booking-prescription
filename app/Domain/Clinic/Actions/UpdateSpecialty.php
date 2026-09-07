<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\SpecialtyData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Specialty;

final class UpdateSpecialty
{
    public function handle(Specialty $specialty, SpecialtyData $data, Actor $actor): Specialty
    {
        $specialty->fill($data->toAttributes())->save();

        return $specialty;
    }
}
