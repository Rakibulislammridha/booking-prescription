<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;

/**
 * renal_impairment (N17–N19) → renal_cautions (the egfr_below-NULL row, else the highest threshold — eGFR is unknown
 * to v1); hepatic_impairment (K70–K77 / B18) → hepatic_cautions (child_pugh_class NULL preferred). avoid → critical,
 * adjust_dose → warning, caution → info; advice text in evidence.
 */
final class RenalHepaticCheck implements SafetyCheck
{
    public function __construct(private readonly CatalogCache $catalog) {}

    public function key(): string
    {
        return 'renal';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $p = $ctx->patient;

        if (! $p->renalImpairment && ! $p->hepaticImpairment) {
            return [];
        }

        $alerts = [];

        foreach ($ctx->itemsWithGeneric() as $item) {
            if ($p->renalImpairment) {
                $rows = $this->catalog->renal((int) $item->genericId);
                $row = array_values(array_filter($rows, fn ($r) => ($r['egfr_below'] ?? null) === null))[0] ?? ($rows[0] ?? null);

                if ($row !== null) {
                    $alerts[] = $this->alert('renal', $item->key, (int) $item->genericId, $item->displayName(), (string) $row['level'], (string) ($row['advice'] ?? ''), ['egfr_below' => $row['egfr_below'] ?? null]);
                }
            }

            if ($p->hepaticImpairment) {
                $rows = $this->catalog->hepatic((int) $item->genericId);
                $row = array_values(array_filter($rows, fn ($r) => ($r['child_pugh_class'] ?? null) === null))[0] ?? ($rows[0] ?? null);

                if ($row !== null) {
                    $alerts[] = $this->alert('hepatic', $item->key, (int) $item->genericId, $item->displayName(), (string) $row['level'], (string) ($row['advice'] ?? ''), ['child_pugh_class' => $row['child_pugh_class'] ?? null]);
                }
            }
        }

        return $alerts;
    }

    /** @param  array<string, mixed>  $extra */
    private function alert(string $organ, string $itemKey, int $genericId, string $name, string $level, string $advice, array $extra): SafetyAlert
    {
        $severity = match ($level) {
            'avoid' => Severity::Critical, 'adjust_dose' => Severity::Warning, default => Severity::Info
        };
        $organEn = $organ === 'renal' ? 'renal' : 'hepatic';
        $organBn = $organ === 'renal' ? 'কিডনি' : 'লিভার';

        return new SafetyAlert($organ, "{$organ}.{$level}", $severity, "{$organ}:{$level}:{$genericId}", true,
            ucfirst($organEn).' caution: '.str_replace('_', ' ', $level),
            "{$name}: {$organEn} impairment — ".str_replace('_', ' ', $level).($advice !== '' ? ". {$advice}" : '.'),
            "{$name}: {$organBn} সমস্যায় — ".str_replace('_', ' ', $level).($advice !== '' ? "। {$advice}" : '।'),
            [$itemKey], [$genericId], ['level' => $level, 'advice' => $advice] + $extra);
    }
}
