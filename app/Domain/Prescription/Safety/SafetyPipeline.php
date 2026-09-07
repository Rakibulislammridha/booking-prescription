<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Enums\SafetySeverity;

/**
 * Runs the configured checks (config('prescription.safety.checks'), PRESCRIPTION.md §5.4) over a SafetyContext,
 * applies the fingerprint-keyed overrides, sorts critical → info and reports what blocks issue (§5.2).
 */
final class SafetyPipeline
{
    /** Codes that can never be overridden (§5.2). */
    public const NON_OVERRIDABLE = ['custom_brand.unlinked', 'catalog.ref_missing', 'parse.error'];

    /** @param  list<SafetyCheck>  $checks */
    public function __construct(private readonly array $checks, private readonly CatalogCache $catalog) {}

    public function run(SafetyContext $ctx): SafetyReport
    {
        $alerts = [];

        foreach ($this->checks as $check) {
            foreach ($check->run($ctx) as $alert) {
                $alerts[$alert->fingerprint] = $alert;                // one alert per fingerprint
            }
        }

        foreach ($alerts as $alert) {
            if ($alert->overridable && isset($ctx->overrides[$alert->fingerprint])) {
                $o = $ctx->overrides[$alert->fingerprint];
                $alert->overridden = ['reason' => (string) $o['reason'], 'by' => isset($o['by']) ? (int) $o['by'] : null, 'at' => isset($o['at']) ? (string) $o['at'] : null];
            }
        }

        $list = array_values($alerts);
        usort($list, fn (SafetyAlert $a, SafetyAlert $b) => [$b->severity->rank(), $a->key, $a->fingerprint] <=> [$a->severity->rank(), $b->key, $b->fingerprint]);

        $blocked = array_values(array_map(fn (SafetyAlert $a) => $a->fingerprint, array_filter($list, fn (SafetyAlert $a) => $a->blocksIssue())));

        return new SafetyReport($list, $blocked, ['items' => $ctx->computed]);
    }

    /** @return list<SafetyCheck> */
    public function checks(): array
    {
        return $this->checks;
    }

    public function catalog(): CatalogCache
    {
        return $this->catalog;
    }

    public static function severityFor(string $level): SafetySeverity
    {
        return SafetySeverity::from($level);
    }
}
