<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety\Checks;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Enums\SafetySeverity as Severity;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyCheck;
use App\Domain\Prescription\Safety\SafetyContext;

/**
 * Pregnant (Z33.1 / O-chapter): pregnancy_categories row for the trimester, else the trimester-NULL row; D → warning,
 * X → critical. Lactating (Z39.1): lactation caution → warning, avoid → critical, unknown → info. Female 10–55 y with
 * unknown status and a D/X drug → one info `pregnancy.status_unknown`.
 */
final class PregnancyLactationCheck implements SafetyCheck
{
    public function __construct(private readonly CatalogCache $catalog) {}

    public function key(): string
    {
        return 'pregnancy';
    }

    /** @return list<SafetyAlert> */
    public function run(SafetyContext $ctx): array
    {
        $p = $ctx->patient;
        $alerts = [];
        $unknownStatusHit = false;

        foreach ($ctx->itemsWithGeneric() as $item) {
            $rows = $this->catalog->pregnancy((int) $item->genericId);

            if ($rows === []) {
                continue;
            }

            $row = null;

            foreach ($rows as $candidate) {
                if ($p->trimester !== null && (int) ($candidate['trimester'] ?? 0) === $p->trimester) {
                    $row = $candidate;

                    break;
                }
            }

            $row ??= array_values(array_filter($rows, fn ($r) => ($r['trimester'] ?? null) === null))[0] ?? $rows[0];
            $category = strtoupper((string) ($row['category'] ?? ''));
            $lactation = (string) ($row['lactation'] ?? 'unknown');

            if ($p->isPregnant && in_array($category, ['D', 'X'], true)) {
                $severity = $category === 'X' ? Severity::Critical : Severity::Warning;
                $code = $category === 'X' ? 'pregnancy.category_x' : 'pregnancy.category_d';
                $alerts[] = new SafetyAlert($this->key(), $code, $severity, "pregnancy:{$code}:{$item->genericId}", true,
                    "Pregnancy category {$category}", "{$item->displayName()} is pregnancy category {$category}".($p->trimester !== null ? " (trimester {$p->trimester})" : '').'.'.(isset($row['notes']) && $row['notes'] !== '' ? ' '.$row['notes'] : ''),
                    "{$item->displayName()} গর্ভাবস্থায় ক্যাটাগরি {$category}".($p->trimester !== null ? " (ত্রৈমাসিক {$p->trimester})" : '').'।',
                    [$item->key], [$item->genericId], ['category' => $category, 'trimester' => $row['trimester'] ?? null, 'notes' => $row['notes'] ?? null]);
            } elseif (! $p->isPregnant && in_array($category, ['D', 'X'], true) && $p->pregnancyStatusUnknownForFertileFemale()) {
                $unknownStatusHit = true;
            }

            if ($p->isLactating && $lactation !== 'safe') {
                $severity = match ($lactation) {
                    'avoid' => Severity::Critical, 'caution' => Severity::Warning, default => Severity::Info
                };
                $alerts[] = new SafetyAlert($this->key(), "lactation.{$lactation}", $severity, "pregnancy:lactation.{$lactation}:{$item->genericId}", true,
                    'Lactation: '.$lactation, "{$item->displayName()}: lactation risk is \"{$lactation}\".", "{$item->displayName()}: স্তন্যদানে ঝুঁকি \"{$lactation}\"।",
                    [$item->key], [$item->genericId], ['lactation' => $lactation]);
            }
        }

        if ($unknownStatusHit) {
            $alerts[] = new SafetyAlert($this->key(), 'pregnancy.status_unknown', Severity::Info, 'pregnancy:status_unknown', true,
                'Pregnancy status unknown', 'A category D/X drug is on the pad and the pregnancy status is not recorded. Set status.',
                'প্যাডে ক্যাটাগরি D/X ওষুধ আছে কিন্তু গর্ভাবস্থার তথ্য নেই। স্ট্যাটাস সেট করুন।', [], [], ['action' => 'set_status']);
        }

        return $alerts;
    }
}
