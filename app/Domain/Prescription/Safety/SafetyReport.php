<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety;

/** Output of SafetyPipeline::run (PRESCRIPTION.md §5.1). */
final class SafetyReport
{
    /**
     * @param  list<SafetyAlert>  $alerts  sorted critical → info
     * @param  list<string>  $issueBlockedBy  fingerprints that block issue
     * @param  array<string, mixed>  $computed  {items: {key: {daily_mg, per_dose_mg, mg_per_kg_day, adult_max_mg_day}}}
     */
    public function __construct(
        public array $alerts = [],
        public array $issueBlockedBy = [],
        public array $computed = ['items' => []],
    ) {}

    public function blocked(): bool
    {
        return $this->issueBlockedBy !== [];
    }

    /** @return list<array<string, mixed>> */
    public function alertsArray(): array
    {
        return array_map(fn (SafetyAlert $a) => $a->toArray(), $this->alerts);
    }

    /**
     * Alerts naming this item (or none when the alert is patient-level).
     *
     * @return list<SafetyAlert>
     */
    public function forItem(string $key): array
    {
        return array_values(array_filter($this->alerts, fn (SafetyAlert $a) => in_array($key, $a->itemKeys, true)));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['alerts' => $this->alertsArray(), 'issue_blocked_by' => $this->issueBlockedBy, 'computed' => $this->computed];
    }
}
