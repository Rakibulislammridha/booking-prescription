<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Domain\Shared\Actor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\DoctorFavourite;

/** DELETE removes a learned row (it can be re-learned); pinned rows must be unpinned first (§3.7). */
final class RemoveFavourite
{
    public function __construct(private readonly DoctorLearningCache $cache) {}

    public function handle(DoctorFavourite $favourite, Actor $actor): void
    {
        if ($favourite->is_pinned) {
            throw new class('Unpin the favourite before removing it.') extends DomainException
            {
                public function code(): string
                {
                    return 'prescriptions.favourite_pinned';
                }

                public function status(): int
                {
                    return 409;
                }
            };
        }

        $doctorId = $favourite->doctor_id;
        $icd = $favourite->icd10_code;
        $favourite->delete();
        $this->cache->refresh($doctorId, $icd !== null ? [$icd] : []);
    }
}
