<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Reports\Concerns;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Enums\PeakMetric;
use App\Domain\Reports\Enums\ReportKind;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Specialty;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The filter bar of every report page and every export, resolved once.
 *
 * Identifiers on the wire are `public_id` ULIDs and slugs (CONVENTIONS §5), so this resolves them to the
 * internal bigints the query objects take — an unknown public id becomes `null` (no filter) rather than a 404,
 * because a stale bookmark should show the clinic's numbers, not an error page.
 *
 * The RANGE is clamped to `MAX_DAYS`. A clinic with three years of data is welcome to ask for three years, but
 * an unbounded range from a crafted query string is a denial-of-service against the database.
 *
 * It is a trait rather than a base class because `pint.json` sets `final_class`: two sibling FormRequests share
 * the parsing, and neither inherits from the other.
 */
trait ResolvesReportFilters
{
    public const MAX_DAYS = 1100;   // just over three years — the brief's own worst case

    public const DEFAULT_DAYS = 29;  // "the last 30 days", inclusive of today

    /**
     * The filter bar's own rules; a request adds its extras on top (`format` for an export).
     *
     * @return array<string, array<int, string>>
     */
    public function filterRules(): array
    {
        return [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'branch' => ['nullable', 'string', 'max:26'],
            'doctor' => ['nullable', 'string', 'max:26'],
            'specialty' => ['nullable', 'string', 'max:120'],
            'method' => ['nullable', 'string', 'max:16'],
            'metric' => ['nullable', 'string', 'in:'.implode(',', PeakMetric::values())],
            'limit' => ['nullable', 'integer', 'between:5,100'],
        ];
    }

    /** Which report this request belongs to — bound by the route, so it cannot be spoofed by a parameter. */
    public function kind(): ReportKind
    {
        $segment = $this->route('report');

        return ReportKind::tryFrom(is_string($segment) ? $segment : '') ?? ReportKind::Dashboard;
    }

    public function toData(): ReportFilters
    {
        $tz = Clock::timezone();
        $today = Clock::today();

        $to = $this->localDate('to', $today);
        $from = $this->localDate('from', $to->subDays(self::DEFAULT_DAYS));

        if ($from->greaterThan($to)) {
            $from = $to;
        }

        // Clamp from the RIGHT: the recent end of a too-wide range is the part a clinic actually looks at.
        if ($from->diffInDays($to) + 1 > self::MAX_DAYS) {
            $from = $to->subDays(self::MAX_DAYS - 1);
        }

        return new ReportFilters(
            from: $from->setTimezone($tz)->startOfDay(),
            to: $to->setTimezone($tz)->startOfDay(),
            branchId: $this->resolve(Branch::class, 'branch'),
            doctorId: $this->resolve(Doctor::class, 'doctor'),
            specialtyId: $this->specialtyId(),
            method: $this->cleanString('method'),
            metric: PeakMetric::tryFrom((string) $this->cleanString('metric')) ?? PeakMetric::Arrivals,
            limit: $this->integer('limit') > 0 ? min(100, max(5, $this->integer('limit'))) : 15,
        );
    }

    /**
     * The raw query values echoed back so the filter bar keeps showing what the user chose.
     *
     * @return array<string, string|null>
     */
    public function rawFilters(): array
    {
        return [
            'from' => $this->cleanString('from'),
            'to' => $this->cleanString('to'),
            'branch' => $this->cleanString('branch'),
            'doctor' => $this->cleanString('doctor'),
            'specialty' => $this->cleanString('specialty'),
            'method' => $this->cleanString('method'),
            'metric' => $this->cleanString('metric'),
        ];
    }

    private function localDate(string $key, CarbonImmutable $default): CarbonImmutable
    {
        $value = $this->cleanString($key);

        return $value === null ? $default : CarbonImmutable::parse($value, Clock::timezone())->startOfDay();
    }

    /**
     * @param  class-string<Model>  $model
     */
    private function resolve(string $model, string $key): ?int
    {
        $value = $this->cleanString($key);

        if ($value === null) {
            return null;
        }

        $id = $model::query()->where('public_id', $value)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    /** Specialties have no `public_id` (SCHEMA §3.1), so they travel by slug. */
    private function specialtyId(): ?int
    {
        $value = $this->cleanString('specialty');

        if ($value === null) {
            return null;
        }

        $id = Specialty::query()->where('slug', $value)->value('id');

        return is_numeric($id) ? (int) $id : null;
    }

    private function cleanString(string $key): ?string
    {
        $value = $this->query($key);
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
