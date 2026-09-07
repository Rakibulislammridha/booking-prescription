<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\GenericExpander;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;

/**
 * Adults (else PediatricDoseCheck handles). daily_mg summed per generic across items (two paracetamol brands add up,
 * combination components counted by their mg share). Row: `elderly` when age ≥ 65 and such a row exists, else `adult`;
 * route-specific row preferred. > max → critical; ≥ 80 % → info; per-dose > max_mg_per_dose → warning.
 */
final class MaxDailyDoseCheck implements SafetyCheck
{
    private GenericExpander $expander;

    public function __construct(private readonly CatalogCache $catalog)
    {
        $this->expander = new GenericExpander($catalog);
    }

    public function key(): string
    {
        return 'max_dose';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $p = $ctx->patient;

        if ($p->isPediatric()) {
            return [];
        }

        $daily = [];                                                  // generic → mg
        $perDose = [];                                                // generic → max per-dose mg
        $keys = [];
        $routeIds = [];

        foreach ($ctx->itemsWithGeneric() as $item) {
            foreach ($this->expander->expand((int) $item->genericId) as $g => $share) {
                $keys[$g][] = $item->key;
                $routeIds[$g] = $item->routeId;

                if ($item->dailyMg !== null && $share !== null) {
                    $daily[$g] = ($daily[$g] ?? 0) + $item->dailyMg * $share;
                }

                if ($item->perDoseMg !== null && $share !== null) {
                    $perDose[$g] = max($perDose[$g] ?? 0, $item->perDoseMg * $share);
                }
            }
        }

        $alerts = [];

        foreach ($keys as $g => $itemKeys) {
            $rows = $this->catalog->maxDoses($g);
            $population = $p->isElderly() && array_filter($rows, fn ($r) => ($r['population'] ?? '') === 'elderly') !== [] ? 'elderly' : 'adult';
            $candidates = array_values(array_filter($rows, fn ($r) => ($r['population'] ?? 'adult') === $population));

            if ($candidates === []) {
                continue;
            }

            usort($candidates, fn ($a, $b) => (($b['route_id'] ?? null) === $routeIds[$g] ? 1 : 0) <=> (($a['route_id'] ?? null) === $routeIds[$g] ? 1 : 0));
            $row = $candidates[0];
            $name = $this->expander->name($g);
            $itemKeys = array_values(array_unique($itemKeys));
            $maxDay = isset($row['max_mg_per_day']) ? (float) $row['max_mg_per_day'] : null;

            foreach ($itemKeys as $k) {
                $ctx->compute($k, ['adult_max_mg_day' => $maxDay]);
            }

            if ($maxDay !== null && $maxDay > 0 && isset($daily[$g])) {
                $ratio = $daily[$g] / $maxDay;

                if ($ratio > 1.0) {
                    $alerts[] = new SafetyAlert($this->key(), 'max_dose.over', Severity::Critical, "max_dose:over:{$g}", true, 'Exceeds maximum daily dose',
                        "{$name}: ".round($daily[$g])." mg/day exceeds the maximum {$maxDay} mg/day ({$population}).",
                        "{$name}: দিনে ".round($daily[$g]).' মিগ্রা, সর্বোচ্চ '.$maxDay.' মিগ্রা/দিন ('.$population.')।',
                        $itemKeys, [$g], ['daily_mg' => round($daily[$g], 2), 'max_mg_per_day' => $maxDay, 'population' => $population, 'ratio' => round($ratio, 2)]);
                } elseif ($ratio >= 0.8) {
                    $alerts[] = new SafetyAlert($this->key(), 'max_dose.near', Severity::Info, "max_dose:near:{$g}", true, 'Near maximum daily dose',
                        "{$name}: ".round($daily[$g]).' mg/day is '.round($ratio * 100)."% of the maximum {$maxDay} mg/day.",
                        "{$name}: দিনে ".round($daily[$g]).' মিগ্রা, সর্বোচ্চের '.round($ratio * 100).'%।',
                        $itemKeys, [$g], ['daily_mg' => round($daily[$g], 2), 'max_mg_per_day' => $maxDay, 'population' => $population, 'ratio' => round($ratio, 2)]);
                }
            }

            $maxDose = isset($row['max_mg_per_dose']) ? (float) $row['max_mg_per_dose'] : null;

            if ($maxDose !== null && $maxDose > 0 && isset($perDose[$g]) && $perDose[$g] > $maxDose) {
                $alerts[] = new SafetyAlert($this->key(), 'max_dose.per_dose', Severity::Warning, "max_dose:per_dose:{$g}", true, 'Single dose above maximum',
                    "{$name}: ".round($perDose[$g])." mg per dose exceeds {$maxDose} mg/dose.", "{$name}: প্রতি ডোজ ".round($perDose[$g])." মিগ্রা, সর্বোচ্চ {$maxDose} মিগ্রা।",
                    $itemKeys, [$g], ['per_dose_mg' => round($perDose[$g], 2), 'max_mg_per_dose' => $maxDose]);
            }
        }

        return $alerts;
    }
}
