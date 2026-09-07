<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Catalog\Search\DoctorUsageBoostProvider;

/**
 * The Prescription module's DoctorUsageBoostProvider (PRESCRIPTION.md §3.3 steps 3–4): usage counts and
 * diagnosis favourites from DoctorLearningCache re-rank the merged autocomplete; `last_shorthand` is the ghost
 * suggestion after the drug chip. Bound over Catalog's NullDoctorUsageBoost by PrescriptionServiceProvider.
 */
final class DoctorUsageBoost implements DoctorUsageBoostProvider
{
    public function __construct(private readonly DoctorLearningCache $cache) {}

    /**
     * @param  list<string>  $dxCodes
     * @param  list<string>  $hitIds
     * @return array<string, array{usage: int, fav_for_dx: bool, last_shorthand: string|null}>
     */
    public function drugBoosts(int $doctorId, array $dxCodes, array $hitIds): array
    {
        $usage = $this->cache->usage($doctorId);
        $favs = [];

        foreach ($dxCodes as $code) {
            foreach ($this->cache->favouritesFor($doctorId, (string) $code) as $key) {
                $favs[$key] = true;
            }
        }

        $out = [];

        foreach ($hitIds as $id) {
            $u = $usage[$id] ?? null;
            $fav = isset($favs[$id]);

            if ($u === null && ! $fav) {
                continue;
            }

            $out[$id] = ['usage' => (int) ($u['use_count'] ?? 0), 'fav_for_dx' => $fav, 'last_shorthand' => $u['last_shorthand'] ?? null];
        }

        return $out;
    }

    /** @return array<string, int> */
    public function icdUsage(int $doctorId): array
    {
        return $this->cache->icdUsage($doctorId);
    }
}
