<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

/** Default binding: no per-doctor signal (pure Meilisearch / popularity ranking). */
final class NullDoctorUsageBoost implements DoctorUsageBoostProvider
{
    public function drugBoosts(int $doctorId, array $dxCodes, array $hitIds): array
    {
        return [];
    }

    public function icdUsage(int $doctorId): array
    {
        return [];
    }
}
