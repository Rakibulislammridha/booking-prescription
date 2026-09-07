<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Reports\Data\ReportFilters;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * A short-lived cache in front of the aggregates (CONVENTIONS §15 Redis keys: `t:{tenantId}:…` with the BIGINT
 * tenant id). Two rules make it safe for numbers a clinic acts on:
 *
 *  1. NOTHING IS INVALIDATED, EVERYTHING EXPIRES. Reports read a dozen tables through half a dozen modules;
 *     an invalidation matrix would be wrong within a week, and a report that is silently wrong is worse than
 *     one that is a minute old. A range that includes today gets 60 s (the desk is still writing to it); a
 *     closed historical range gets 15 minutes, because the past does not change — except when it does (a
 *     backdated refund), which the 15 minutes still bounds.
 *  2. EVERY PAYLOAD CARRIES `generated_at`, and every page prints it. A cached number is never presented as a
 *     live one; the user always sees the moment it was computed.
 *
 * The key includes the filter fingerprint AND the tenant id, so two clinics can never share a row, and
 * `flushTenant()` exists for the rare case (a data repair) where an operator wants the next read to recompute.
 */
final class ReportCache
{
    public const TTL_LIVE_SECONDS = 60;

    public const TTL_HISTORIC_SECONDS = 900;

    /**
     * @param  Closure(): array<string, mixed>  $compute
     * @return array<string, mixed>
     */
    public function remember(string $report, ReportFilters $filters, Closure $compute): array
    {
        $key = $this->key($report, $filters);
        $hit = Cache::get($key);

        if (is_array($hit) && isset($hit['data'], $hit['generated_at'])) {
            return $hit + ['cached' => true];
        }

        $payload = [
            'data' => $compute(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'ttl' => $this->ttl($filters),
        ];

        Cache::put($key, $payload, $payload['ttl']);

        return $payload + ['cached' => false];
    }

    public function forget(string $report, ReportFilters $filters): void
    {
        Cache::forget($this->key($report, $filters));
    }

    /** Bump the tenant's cache generation: every existing key becomes unreachable, none has to be enumerated. */
    public function flushTenant(): void
    {
        Cache::increment($this->generationKey());
    }

    public function key(string $report, ReportFilters $filters): string
    {
        return sprintf('t:%s:rep:%d:%s:%s', (string) (Tenancy::id() ?? 'central'), $this->generation(), $report, $filters->fingerprint());
    }

    public function ttl(ReportFilters $filters): int
    {
        return $filters->includesToday() ? self::TTL_LIVE_SECONDS : self::TTL_HISTORIC_SECONDS;
    }

    private function generation(): int
    {
        $value = Cache::get($this->generationKey());

        return is_numeric($value) ? (int) $value : 0;
    }

    private function generationKey(): string
    {
        return 't:'.(string) (Tenancy::id() ?? 'central').':rep:gen';
    }
}
