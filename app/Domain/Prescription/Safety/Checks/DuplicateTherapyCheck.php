<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\GenericExpander;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;
use Illuminate\Support\Str;

/**
 * Two items with the same generic (components expanded) → warning `duplicate.generic` (critical when both are
 * systemic on the same route); same therapeutic_class → info `duplicate.class`; generic already on the patient's
 * active medication list → info `duplicate.already_on`.
 */
final class DuplicateTherapyCheck implements SafetyCheck
{
    private GenericExpander $expander;

    public function __construct(private readonly CatalogCache $catalog)
    {
        $this->expander = new GenericExpander($catalog);
    }

    public function key(): string
    {
        return 'duplicate';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $items = $ctx->itemsWithGeneric();
        $byGeneric = [];                                              // generic → list of items

        foreach ($items as $item) {
            foreach ($this->expander->ids((int) $item->genericId) as $g) {
                $byGeneric[$g][] = $item;
            }
        }

        $alerts = [];

        foreach ($byGeneric as $g => $list) {
            if (count($list) < 2) {
                continue;
            }

            $name = $this->expander->name($g);
            $routes = array_unique(array_map(fn ($i) => $i->routeCode ?? 'default', $list));
            $allSystemic = array_filter($list, fn ($i) => ! $i->isSystemic) === [];
            $severity = $allSystemic && count($routes) === 1 ? Severity::Critical : Severity::Warning;

            $alerts[] = new SafetyAlert($this->key(), 'duplicate.generic', $severity, "duplicate:generic:{$g}", true, 'Duplicate therapy',
                "{$name} appears more than once (".implode(', ', array_map(fn ($i) => $i->displayName(), $list)).').',
                "{$name} একাধিকবার আছে (".implode(', ', array_map(fn ($i) => $i->displayName(), $list)).')।',
                array_map(fn ($i) => $i->key, $list), [$g], ['generic' => $name, 'same_route' => count($routes) === 1, 'systemic' => $allSystemic]);
        }

        $byClass = [];

        foreach ($items as $item) {
            $class = $this->catalog->generic((int) $item->genericId)['therapeutic_class'] ?? null;

            if (is_string($class) && $class !== '') {
                $byClass[$class][$item->genericId] = $item;
            }
        }

        foreach ($byClass as $class => $members) {
            if (count($members) < 2) {
                continue;
            }

            $ids = array_keys($members);
            sort($ids);
            $names = implode(', ', array_map(fn ($i) => $i->displayName(), array_values($members)));
            $alerts[] = new SafetyAlert($this->key(), 'duplicate.class', Severity::Info, 'duplicate:class:'.Str::slug($class).':'.implode(':', $ids), true,
                'Same therapeutic class', "Two drugs of the same class ({$class}): {$names}.", "একই শ্রেণির দুটি ওষুধ ({$class}): {$names}।",
                array_map(fn ($i) => $i->key, array_values($members)), $ids, ['therapeutic_class' => $class]);
        }

        foreach ($items as $item) {
            foreach ($this->expander->ids((int) $item->genericId) as $g) {
                if (in_array($g, $ctx->patient->currentMedicationGenericIds, true)) {
                    $current = $ctx->patient->currentMedicationNames[$g] ?? $this->expander->name($g);
                    $alerts[] = new SafetyAlert($this->key(), 'duplicate.already_on', Severity::Info, "duplicate:already_on:{$g}", true,
                        'Already on this medication', "Patient is already taking {$current}.", "রোগী ইতিমধ্যে {$current} নিচ্ছেন।",
                        [$item->key], [$g], ['current_medication' => $current]);
                }
            }
        }

        return $alerts;
    }
}
