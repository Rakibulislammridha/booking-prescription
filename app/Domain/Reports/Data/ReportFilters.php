<?php

declare(strict_types=1);

namespace App\Domain\Reports\Data;

use App\Domain\Reports\Enums\PeakMetric;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * What every report query is asked. `$from`/`$to` are clinic-local calendar days and the range is INCLUSIVE of
 * both — "1 to 31 March" means the whole of 31 March in Dhaka, so the UTC window the timestamp columns are
 * compared against is [1 Mar 00:00 +06 → 1 Apr 00:00 +06), i.e. 28 Feb 18:00Z → 31 Mar 18:00Z.
 *
 * The half-open upper bound matters: `BETWEEN … AND endOfDay()` loses the last microsecond of the day, and a
 * payment stamped 23:59:59.7 local would vanish from the month it belongs to.
 */
final readonly class ReportFilters
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?int $branchId = null,
        public ?int $doctorId = null,
        public ?int $specialtyId = null,
        public ?string $method = null,
        public PeakMetric $metric = PeakMetric::Arrivals,
        public int $limit = 15,
    ) {}

    /** Clinic-local days, clamped so `from <= to`, with the tenant timezone applied. */
    public static function forDays(string $from, string $to, ?int $branchId = null, ?int $doctorId = null): self
    {
        $tz = Clock::timezone();
        $start = CarbonImmutable::parse($from, $tz)->startOfDay();
        $end = CarbonImmutable::parse($to, $tz)->startOfDay();

        return new self($start->greaterThan($end) ? $end : $start, $end, $branchId, $doctorId);
    }

    public function withDoctor(?int $doctorId): self
    {
        return new self($this->from, $this->to, $this->branchId, $doctorId, $this->specialtyId, $this->method, $this->metric, $this->limit);
    }

    public function withRange(CarbonImmutable $from, CarbonImmutable $to): self
    {
        return new self($from, $to, $this->branchId, $this->doctorId, $this->specialtyId, $this->method, $this->metric, $this->limit);
    }

    /** Today only, in the clinic's timezone — the dashboard's window. */
    public function today(): self
    {
        return $this->withRange(Clock::today(), Clock::today());
    }

    public function fromDate(): string
    {
        return $this->from->toDateString();
    }

    public function toDate(): string
    {
        return $this->to->toDateString();
    }

    /** Inclusive day count: 1 March to 1 March is one day. */
    public function days(): int
    {
        return (int) $this->from->diffInDays($this->to) + 1;
    }

    /** First instant of the range in UTC. */
    public function startUtc(): CarbonImmutable
    {
        return $this->from->setTimezone(Clock::timezone())->startOfDay()->utc();
    }

    /** EXCLUSIVE upper bound: midnight at the start of the day after `$to`, in UTC. */
    public function endUtc(): CarbonImmutable
    {
        return $this->to->setTimezone(Clock::timezone())->startOfDay()->addDay()->utc();
    }

    /**
     * INCLUSIVE upper bound, for Billing's query objects only: `CollectionReportQuery` compares with
     * `whereBetween`, so it must be handed the same `endOfDay()` instant its own `window()` would compute.
     * Everything in this module uses the half-open `endUtc()` instead.
     */
    public function endInclusiveUtc(): CarbonImmutable
    {
        return $this->to->setTimezone(Clock::timezone())->endOfDay()->utc();
    }

    public function includesToday(): bool
    {
        $today = Clock::today();

        return ! $this->from->greaterThan($today) && ! $this->to->lessThan($today);
    }

    /** Day buckets up to a quarter, weeks up to two years, months beyond — a chart never gets 1 000 points. */
    public function granularity(): string
    {
        return match (true) {
            $this->days() <= 92 => 'day',
            $this->days() <= 730 => 'week',
            default => 'month',
        };
    }

    /**
     * The filter shape Billing's CollectionReportQuery / RevenueShareReportQuery already accept.
     *
     * @return array<string, int|string>
     */
    public function billingFilters(): array
    {
        return array_filter([
            'branch_id' => $this->branchId,
            'doctor_id' => $this->doctorId,
            'method' => $this->method,
        ], fn (mixed $v): bool => $v !== null);
    }

    /** @return array<string, mixed> the wire shape the page reflects back into its filter bar */
    public function toArray(): array
    {
        return [
            'from' => $this->fromDate(),
            'to' => $this->toDate(),
            'branch_id' => $this->branchId,
            'doctor_id' => $this->doctorId,
            'specialty_id' => $this->specialtyId,
            'method' => $this->method,
            'metric' => $this->metric->value,
            'limit' => $this->limit,
            'granularity' => $this->granularity(),
        ];
    }

    /** Stable identity for the cache key: the same filters must hash the same whatever order they arrived in. */
    public function fingerprint(): string
    {
        $parts = $this->toArray();
        ksort($parts);

        return substr(hash('sha256', (string) json_encode($parts).'|'.Clock::timezone()), 0, 24);
    }
}
