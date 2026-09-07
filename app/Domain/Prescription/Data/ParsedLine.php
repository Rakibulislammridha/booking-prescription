<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * The complete parse result = prescription_items.dose_json v1 (PRESCRIPTION.md §2.11). The PHP and TS parsers must
 * agree byte-for-byte on toArray(). Mutable while the Assembler / QuantityCalculator fill it; treat as a value
 * afterwards. DoseJson holds the scalar-column projections.
 */
final class ParsedLine
{
    public const V = 1;

    /** @var array<string, mixed>|null */
    public ?array $schedule = null;

    public ?float $dailyTotal = null;

    /** @var array<string, mixed>|null */
    public ?array $duration = null;

    public string $timing = 'any';

    public ?string $timingCode = null;

    public ?string $routeCode = null;

    /** @var array{value: float|int|null, unit: string, source: string, basis: string|null} */
    public array $quantity = ['value' => null, 'unit' => 'tab', 'source' => 'none', 'basis' => null];

    public ?string $instruction = null;

    /** @var list<ParseIssue> */
    public array $issues = [];

    public function __construct(
        public string $raw,
        public string $normalized = '',
        public string $unit = 'tab',
        public bool $unitInferred = false,
    ) {}

    public function hasErrors(): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->isError()) {
                return true;
            }
        }

        return false;
    }

    /** @return list<ParseIssue> */
    public function errors(): array
    {
        return array_values(array_filter($this->issues, fn (ParseIssue $i) => $i->isError()));
    }

    public function hasIssue(string $code): bool
    {
        foreach ($this->issues as $issue) {
            if ($issue->code === $code) {
                return true;
            }
        }

        return false;
    }

    public function scheduleType(): ?string
    {
        return $this->schedule['type'] ?? null;
    }

    /** Days the quantity is computed over: days | assumed_days | null. */
    public function effectiveDays(): ?int
    {
        return match ($this->duration['type'] ?? null) {
            'days' => (int) $this->duration['days'],
            'continuous' => (int) $this->duration['assumed_days'],
            default => null,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'v' => self::V,
            'raw' => $this->raw,
            'normalized' => $this->normalized,
            'unit' => $this->unit,
            'unit_inferred' => $this->unitInferred,
            'schedule' => $this->schedule,
            'daily_total' => $this->dailyTotal,
            'duration' => $this->duration,
            'timing' => $this->timing,
            'timing_code' => $this->timingCode,
            'route_code' => $this->routeCode,
            'quantity' => $this->quantity,
            'instruction' => $this->instruction,
            'issues' => array_map(fn (ParseIssue $i) => $i->toArray(), $this->issues),
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        $line = new self((string) ($a['raw'] ?? ''), (string) ($a['normalized'] ?? ''), (string) ($a['unit'] ?? 'tab'), (bool) ($a['unit_inferred'] ?? false));
        $line->schedule = isset($a['schedule']) && is_array($a['schedule']) ? $a['schedule'] : null;
        $line->dailyTotal = isset($a['daily_total']) ? (float) $a['daily_total'] : null;
        $line->duration = isset($a['duration']) && is_array($a['duration']) ? $a['duration'] : null;
        $line->timing = (string) ($a['timing'] ?? 'any');
        $line->timingCode = isset($a['timing_code']) ? (string) $a['timing_code'] : null;
        $line->routeCode = isset($a['route_code']) ? (string) $a['route_code'] : null;
        $q = is_array($a['quantity'] ?? null) ? $a['quantity'] : [];
        $line->quantity = ['value' => $q['value'] ?? null, 'unit' => (string) ($q['unit'] ?? $line->unit), 'source' => (string) ($q['source'] ?? 'none'), 'basis' => isset($q['basis']) ? (string) $q['basis'] : null];
        $line->instruction = isset($a['instruction']) ? (string) $a['instruction'] : null;
        $line->issues = array_map(fn ($i) => ParseIssue::fromArray((array) $i), array_values((array) ($a['issues'] ?? [])));

        return $line;
    }
}
