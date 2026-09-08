<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use App\Domain\SaaS\Enums\UsageMetric;

/** One row of the usage-against-limits table shown in the panel and the super console. */
final readonly class LimitStatus
{
    public function __construct(
        public UsageMetric $metric,
        public int $used,
        public ?int $limit,
        public string $period,
    ) {}

    public function isUnlimited(): bool
    {
        return $this->limit === null;
    }

    public function remaining(): ?int
    {
        return $this->limit === null ? null : max(0, $this->limit - $this->used);
    }

    /** 0–100; 100 when a zero limit is in force, null when unlimited. */
    public function percent(): ?int
    {
        if ($this->limit === null) {
            return null;
        }

        if ($this->limit === 0) {
            return 100;
        }

        return (int) min(100, (int) round($this->used * 100 / $this->limit));
    }

    public function isExhausted(): bool
    {
        return $this->limit !== null && $this->used >= $this->limit;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'metric' => $this->metric->value,
            'used' => $this->used,
            'limit' => $this->limit,
            'period' => $this->period,
            'remaining' => $this->remaining(),
            'percent' => $this->percent(),
            'exhausted' => $this->isExhausted(),
            'is_bytes' => $this->metric->isBytes(),
        ];
    }
}
