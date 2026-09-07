<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Search;

/**
 * Hook for the per-doctor learning signals that re-rank autocomplete (PRESCRIPTION.md §3.3 steps 3–4, §3.5).
 * The Prescription module binds its Redis-backed implementation (t:{tenantId}:doctor:{doctorId}:usage / :fav:{icd}
 * / :icd); the Catalog module ships NullDoctorUsageBoost so search works before it exists.
 */
interface DoctorUsageBoostProvider
{
    /**
     * @param  list<string>  $dxCodes  diagnosis codes on the pad
     * @param  list<string>  $hitIds  candidate document ids (s123, g17, c55)
     * @return array<string, array{usage: int, fav_for_dx: bool, last_shorthand: string|null}> keyed by document id; absent = no signal
     */
    public function drugBoosts(int $doctorId, array $dxCodes, array $hitIds): array;

    /** @return array<string, int> ICD-10 code → the doctor's recent usage count */
    public function icdUsage(int $doctorId): array;
}
