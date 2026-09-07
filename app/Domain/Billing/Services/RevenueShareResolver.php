<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Data\RevenueSplit;
use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\RevenueShareType;
use App\Models\Tenant\DoctorRevenueShare;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Which commission rule applies, and what the split is in whole paisa (BRIEF §5.I "doctor revenue share").
 *
 * Rule resolution (SCHEMA §3.5): most specific wins — a rule with a `branch_id` beats an all-branch rule, an exact
 * `item_type` beats `all`; newest `effective_from` breaks ties. The winner and the two paisa amounts are
 * SNAPSHOTTED onto the invoice item at issue, so editing or retiring the rule never rewrites a historical split.
 *
 * The split is exhaustive by construction: `clinic = line_total − doctor`, so no paisa is created or lost to
 * rounding, whatever the percentage.
 */
final class RevenueShareResolver
{
    /** @var array<int, Collection<int, DoctorRevenueShare>> */
    private array $cache = [];

    public function resolve(int $doctorId, ?int $branchId, InvoiceItemType $itemType, CarbonImmutable $on): ?DoctorRevenueShare
    {
        $candidates = $this->rulesFor($doctorId)
            ->filter(fn (DoctorRevenueShare $rule) => $rule->item_type->matches($itemType))
            ->filter(fn (DoctorRevenueShare $rule) => $rule->branch_id === null || $rule->branch_id === $branchId)
            ->filter(fn (DoctorRevenueShare $rule) => $rule->effective_from->lessThanOrEqualTo($on))
            ->filter(fn (DoctorRevenueShare $rule) => $rule->effective_to === null || $rule->effective_to->greaterThanOrEqualTo($on));

        return $candidates
            ->sortByDesc(fn (DoctorRevenueShare $rule) => sprintf(
                '%d%d%s%010d',
                $rule->branch_id === null ? 0 : 1,                     // branch-specific beats all-branch
                $rule->item_type->value === 'all' ? 0 : 1,             // exact item type beats the catch-all
                $rule->effective_from->format('Ymd'),                  // newest rule wins a tie
                $rule->id,
            ))
            ->first();
    }

    /** The split for one line. Without a rule the clinic keeps everything (doctor share 0). */
    public function split(int $lineTotalPaisa, ?DoctorRevenueShare $rule): RevenueSplit
    {
        $lineTotalPaisa = max(0, $lineTotalPaisa);

        if ($rule === null) {
            return new RevenueSplit(null, 0, $lineTotalPaisa);
        }

        $doctor = $rule->share_type === RevenueShareType::Percentage
            ? Paisa::applyBasisPoints($lineTotalPaisa, Paisa::percentToBasisPoints($rule->share_value))
            : Paisa::fromDecimal($rule->share_value);

        $doctor = Paisa::clamp($doctor, $lineTotalPaisa);

        // The residue always goes to the clinic, so doctor + clinic === line_total exactly (a database CHECK).
        return new RevenueSplit($rule->id, $doctor, $lineTotalPaisa - $doctor);
    }

    /**
     * Per-request memo: one invoice touches the same doctor's rules once per line.
     *
     * @return Collection<int, DoctorRevenueShare>
     */
    private function rulesFor(int $doctorId): Collection
    {
        return $this->cache[$doctorId] ??= DoctorRevenueShare::query()
            ->where('doctor_id', $doctorId)
            ->where('is_active', true)
            ->get();
    }

    /** Octane/queue safety: the memo never outlives one unit of work. */
    public function forget(): void
    {
        $this->cache = [];
    }
}
