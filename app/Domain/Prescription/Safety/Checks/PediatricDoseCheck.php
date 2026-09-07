<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;

/**
 * Applies when the patient is pediatric (PatientSafetyProfile::isPediatric). Weight missing → one warning. Picks the
 * `pediatric` max_daily_doses row whose age window contains the patient (route-scoped row preferred); age below every
 * row → critical `pediatric.contraindicated_age`. mg/kg/day, daily and per-dose ratios: > 1.5× → critical, > 1× →
 * warning, ≥ 0.8× → info. computed.items[key].mg_per_kg_day feeds the interpretation line.
 */
final class PediatricDoseCheck implements SafetyCheck
{
    public function __construct(private readonly CatalogCache $catalog) {}

    public function key(): string
    {
        return 'pediatric';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $p = $ctx->patient;

        if (! $p->isPediatric()) {
            return [];
        }

        $alerts = [];

        if ($p->weightKg === null) {
            $alerts[] = new SafetyAlert($this->key(), 'pediatric.weight_missing', Severity::Warning, 'pediatric:weight_missing', true,
                'Weight needed for pediatric dosing', 'Weight needed for pediatric dosing (mg/kg cannot be checked).', 'শিশুর ডোজ (মিগ্রা/কেজি) যাচাইয়ের জন্য ওজন প্রয়োজন।',
                [], [], ['age_months' => $p->ageMonths]);
        }

        foreach ($ctx->itemsWithGeneric() as $item) {
            $rows = array_values(array_filter($this->catalog->maxDoses((int) $item->genericId), fn ($r) => ($r['population'] ?? 'adult') === 'pediatric'));

            if ($rows === []) {
                continue;
            }

            $age = (int) $p->ageMonths;
            $eligible = array_values(array_filter($rows, fn ($r) => ($r['min_age_months'] === null || $age >= (int) $r['min_age_months']) && ($r['max_age_months'] === null || $age <= (int) $r['max_age_months'])));

            if ($eligible === []) {
                $minAges = array_filter(array_map(fn ($r) => $r['min_age_months'], $rows), fn ($v) => $v !== null);

                if ($minAges !== [] && $age < min($minAges)) {
                    $alerts[] = new SafetyAlert($this->key(), 'pediatric.contraindicated_age', Severity::Critical, "pediatric:contraindicated_age:{$item->genericId}", true,
                        'Below licensed age', "{$item->displayName()} is not licensed under ".(int) min($minAges).' months of age.',
                        "{$item->displayName()} ".(int) min($minAges).' মাসের নিচে অনুমোদিত নয়।', [$item->key], [$item->genericId], ['min_age_months' => (int) min($minAges), 'age_months' => $age]);
                }

                continue;
            }

            usort($eligible, fn ($a, $b) => (($b['route_id'] ?? null) === $item->routeId ? 1 : 0) <=> (($a['route_id'] ?? null) === $item->routeId ? 1 : 0));
            $row = $eligible[0];
            $mgPerKg = $item->dailyMg !== null && $p->weightKg !== null && $p->weightKg > 0 ? round($item->dailyMg / $p->weightKg, 2) : null;
            $ctx->compute($item->key, ['daily_mg' => $item->dailyMg, 'per_dose_mg' => $item->perDoseMg, 'mg_per_kg_day' => $mgPerKg,
                'pediatric_max_mg_per_kg_day' => isset($row['max_mg_per_kg_per_day']) ? (float) $row['max_mg_per_kg_per_day'] : null]);

            $ratios = [];

            if ($mgPerKg !== null && isset($row['max_mg_per_kg_per_day']) && (float) $row['max_mg_per_kg_per_day'] > 0) {
                $ratios['mg_per_kg_day'] = $mgPerKg / (float) $row['max_mg_per_kg_per_day'];
            }

            if ($item->dailyMg !== null && isset($row['max_mg_per_day']) && (float) $row['max_mg_per_day'] > 0) {
                $ratios['daily_mg'] = $item->dailyMg / (float) $row['max_mg_per_day'];
            }

            if ($item->perDoseMg !== null && isset($row['max_mg_per_dose']) && (float) $row['max_mg_per_dose'] > 0) {
                $ratios['per_dose_mg'] = $item->perDoseMg / (float) $row['max_mg_per_dose'];
            }

            if ($ratios === []) {
                continue;
            }

            $ratio = max($ratios);
            $basis = array_search($ratio, $ratios, true);
            $severity = match (true) {
                $ratio > 1.5 => Severity::Critical,
                $ratio > 1.0 => Severity::Warning,
                $ratio >= 0.8 => Severity::Info,
                default => null,
            };

            if ($severity === null) {
                continue;
            }

            $code = $ratio > 1.0 ? 'pediatric.over_max' : 'pediatric.near_max';
            $limitText = $basis === 'mg_per_kg_day' ? $row['max_mg_per_kg_per_day'].' mg/kg/day' : ($basis === 'daily_mg' ? $row['max_mg_per_day'].' mg/day' : $row['max_mg_per_dose'].' mg/dose');
            $given = $basis === 'mg_per_kg_day' ? "{$mgPerKg} mg/kg/day" : ($basis === 'daily_mg' ? "{$item->dailyMg} mg/day" : "{$item->perDoseMg} mg/dose");

            $alerts[] = new SafetyAlert($this->key(), $code, $severity, "pediatric:{$code}:{$item->genericId}:{$severity->value}", true,
                $ratio > 1.0 ? 'Pediatric dose exceeds maximum' : 'Pediatric dose near maximum',
                "{$item->displayName()}: {$given} vs max {$limitText} (".round($ratio * 100).'%).',
                "{$item->displayName()}: {$given}, সর্বোচ্চ {$limitText} (".round($ratio * 100).'%)।',
                [$item->key], [$item->genericId], ['ratio' => round($ratio, 2), 'basis' => $basis, 'limit' => $limitText, 'given' => $given, 'weight_kg' => $p->weightKg, 'age_months' => $age]);
        }

        return $alerts;
    }
}
