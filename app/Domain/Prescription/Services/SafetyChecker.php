<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Data\ResolvedItem;
use App\Domain\Prescription\Enums\SafetySeverity;
use App\Domain\Prescription\Enums\SafetyStage;
use App\Domain\Prescription\Safety\SafetyAlert;
use App\Domain\Prescription\Safety\SafetyPipeline;
use App\Domain\Prescription\Safety\SafetyReport;
use App\Models\Tenant\Prescription;

/**
 * Runs the SafetyPipeline for a prescription's resolved items (draft save, explicit check, issue). Adds the
 * `parse.error` alert for lines whose server parse failed so the writer sees one alert list (§5.2).
 */
final class SafetyChecker
{
    public function __construct(
        private readonly SafetyPipeline $pipeline,
        private readonly SafetyContextBuilder $contexts,
        private readonly CatalogCache $catalog,
    ) {}

    /**
     * @param  list<ResolvedItem>  $items
     * @param  array<string, array{reason: string, by: int|null, at: string|null}>|null  $overrides  null = from the items
     */
    public function check(Prescription $rx, array $items, SafetyStage $stage, ?array $overrides = null, ?int $userId = null): SafetyReport
    {
        $overrides ??= SafetyContextBuilder::overridesFrom($items, $userId);
        $ctx = $this->contexts->build($rx->id, $stage, $rx->visit, $items, $overrides);
        $report = $this->pipeline->run($ctx);

        foreach ($items as $item) {
            if ($item->parsed->hasErrors()) {
                $report->alerts[] = new SafetyAlert('parse', 'parse.error', SafetySeverity::Critical, "parse:error:{$item->key}", false,
                    'Line cannot be parsed', 'Fix the shorthand before issuing.', 'ইস্যু করার আগে শর্টহ্যান্ড ঠিক করুন।', [$item->key],
                    $item->drug?->genericId !== null ? [$item->drug->genericId] : [], ['issues' => array_map(fn ($i) => $i->toArray(), $item->parsed->errors())]);
                $report->issueBlockedBy[] = "parse:error:{$item->key}";
            }
        }

        return $report;
    }

    public function catalogVersion(): string
    {
        return $this->catalog->currentVersion();
    }

    /**
     * {critical: n, warning: n, info: n} for the issue audit row.
     *
     * @return array<string, int>
     */
    public static function summary(SafetyReport $report): array
    {
        $summary = ['critical' => 0, 'warning' => 0, 'info' => 0, 'overridden' => 0];

        foreach ($report->alerts as $alert) {
            $summary[$alert->severity->value]++;

            if ($alert->overridden !== null) {
                $summary['overridden']++;
            }
        }

        return $summary;
    }
}
