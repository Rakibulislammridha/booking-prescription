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
 * All unordered pairs over items[].generic_id ∪ patient.current_medication_generic_ids (pairs with at least one
 * prescribed item), looked up by (least, greatest) through CatalogCache::interactions (one batch). contraindicated →
 * critical; major / moderate → warning; minor → info. Combination generics expand through their components.
 */
final class InteractionCheck implements SafetyCheck
{
    private GenericExpander $expander;

    public function __construct(private readonly CatalogCache $catalog)
    {
        $this->expander = new GenericExpander($catalog);
    }

    public function key(): string
    {
        return 'interaction';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $prescribed = [];                                             // generic id → list of item keys

        foreach ($ctx->itemsWithGeneric() as $item) {
            foreach ($this->expander->ids((int) $item->genericId) as $g) {
                $prescribed[$g][] = $item->key;
            }
        }

        if ($prescribed === []) {
            return [];
        }

        $current = array_values(array_diff($ctx->patient->currentMedicationGenericIds, array_keys($prescribed)));
        $pool = array_unique([...array_keys($prescribed), ...$current]);
        $pairs = [];

        foreach ($pool as $a) {
            foreach ($pool as $b) {
                if ($a < $b && (isset($prescribed[$a]) || isset($prescribed[$b]))) {
                    $pairs[] = [$a, $b];
                }
            }
        }

        $alerts = [];

        foreach ($this->catalog->interactions($pairs) as $pair => $row) {
            [$a, $b] = array_map('intval', explode(':', $pair));
            $severity = (string) $row['severity'];
            $level = match ($severity) {
                'contraindicated' => Severity::Critical,
                'major', 'moderate' => Severity::Warning,
                default => Severity::Info,
            };
            $nameA = $this->expander->name($a);
            $nameB = $this->expander->name($b);
            $withCurrent = ! isset($prescribed[$a]) || ! isset($prescribed[$b]);
            $currentName = $withCurrent ? (isset($prescribed[$a]) ? $nameB : $nameA) : null;
            $keys = array_values(array_unique([...($prescribed[$a] ?? []), ...($prescribed[$b] ?? [])]));
            $management = isset($row['management']) && $row['management'] !== '' ? ' Management: '.$row['management'].'.' : '';
            $prefix = $withCurrent ? "with current medication {$currentName}: " : '';

            $alerts[] = new SafetyAlert(
                key: $this->key(), code: "interaction.{$severity}", severity: $level,
                fingerprint: "interaction:{$severity}:{$a}:{$b}", overridable: true,
                title: match ($severity) {
                    'contraindicated' => 'Contraindicated combination', 'major' => 'Major interaction', 'moderate' => 'Moderate interaction', default => 'Minor interaction'
                },
                message: ucfirst($prefix)."{$nameA} + {$nameB}: {$row['effect']}.{$management}",
                messageBn: ($withCurrent ? "বর্তমান ওষুধ {$currentName}-এর সাথে: " : '')."{$nameA} + {$nameB}: {$row['effect']}।".($management !== '' ? ' ব্যবস্থাপনা: '.$row['management'].'।' : ''),
                itemKeys: $keys, genericIds: [$a, $b],
                evidence: ['pair' => [$nameA, $nameB], 'severity_source' => $severity, 'mechanism' => $row['mechanism'] ?? null, 'effect' => $row['effect'] ?? null,
                    'management' => $row['management'] ?? null, 'evidence_level' => $row['evidence_level'] ?? null, 'source' => $row['source'] ?? null,
                    'with_current_medication' => $withCurrent ? $currentName : null],
            );
        }

        return $alerts;
    }
}
