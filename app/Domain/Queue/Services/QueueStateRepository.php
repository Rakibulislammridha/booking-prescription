<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Exceptions\TenancyNotInitialized;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * The only accessor of the Redis QueueState keys (SCHEMA.md §5.7, REALTIME.md §4.2):
 *   t:{tenantId}:qs:{sessionPublicId}     the JSON document
 *   t:{tenantId}:qs:{sessionPublicId}:v   mirror of session_instances.version (the cheap 304 path)
 * {tenantId} is the bigint Tenancy::id(); the version is bumped inside the mutating transaction (SERIAL_ENGINE §6.4), so it
 * is monotonic, transactional and survives a Redis flush — Redis only mirrors it.
 */
final class QueueStateRepository
{
    public const TTL_MIN = 7200;                                  // 2 h after planned end, at least 2 h

    public const LOCK_SECONDS = 5;

    public const LOCK_WAIT = 3;

    public const LOCK_TIMEOUT_MS = 250;   // serials.estimated_call_at writes yield instead of blocking an allocation

    public function __construct(private readonly QueueStateBuilder $builder) {}

    public function key(int $tenantId, string $sessionPublicId): string
    {
        return "t:{$tenantId}:qs:{$sessionPublicId}";
    }

    public function versionKey(int $tenantId, string $sessionPublicId): string
    {
        return $this->key($tenantId, $sessionPublicId).':v';
    }

    public static function lockName(string $sessionPublicId): string
    {
        return "qs-build:{$sessionPublicId}";
    }

    /** Cheap read path used by the poll endpoint: version only (1 GET). */
    public function version(SessionInstance $s): ?int
    {
        $raw = Redis::get($this->versionKey($this->tenantId(), $s->public_id));

        return is_numeric($raw) ? (int) $raw : null;
    }

    /** Snapshot JSON string or null (1 GET). */
    public function snapshot(SessionInstance $s): ?string
    {
        $raw = Redis::get($this->key($this->tenantId(), $s->public_id));

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /** Version by session public id — the send-time read path of the queued broadcasts (1 GET). */
    public function versionByPublicId(string $sessionPublicId): ?int
    {
        $raw = Redis::get($this->versionKey($this->tenantId(), $sessionPublicId));

        return is_numeric($raw) ? (int) $raw : null;
    }

    /** Snapshot JSON by session public id, or null (1 GET). */
    public function snapshotByPublicId(string $sessionPublicId): ?string
    {
        $raw = Redis::get($this->key($this->tenantId(), $sessionPublicId));

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    /**
     * Decoded snapshot by session public id, or null. Used by QueueStateUpdated::broadcastWith() so a coalesced push
     * always carries the newest document Redis holds (REALTIME.md §4.4), never the one captured at dispatch time.
     *
     * @return array<string, mixed>|null
     */
    public function stateByPublicId(string $sessionPublicId): ?array
    {
        $json = $this->snapshotByPublicId($sessionPublicId);

        if ($json === null) {
            return null;
        }

        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Decoded snapshot (or a fresh rebuild on a cold cache).
     *
     * @return array<string, mixed>
     */
    public function state(SessionInstance $s): array
    {
        $json = $this->snapshot($s);

        if ($json !== null) {
            $decoded = json_decode($json, true);

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $this->rebuild($s);
    }

    /**
     * Rebuild under a short lock; returns the new state. Called by the InvalidateQueueState listener after commit and
     * inline by the poll endpoint on a cold cache. The lock serialises concurrent rebuilds; the row is re-read under
     * the lock and the write is skipped when Redis already holds a higher version.
     *
     * @return array<string, mixed>
     */
    public function rebuild(SessionInstance $s): array
    {
        $tenantId = $this->tenantId();

        /** @var array<string, mixed> $state */
        $state = Cache::lock(self::lockName($s->public_id), self::LOCK_SECONDS)->block(self::LOCK_WAIT, function () use ($s, $tenantId): array {
            /** @var SessionInstance $fresh */
            $fresh = SessionInstance::query()->with(['doctor', 'branch'])->whereKey($s->id)->firstOrFail();   // the committed version
            $now = now();
            $state = $this->builder->build($fresh, $fresh->version, $now);

            $held = Redis::get($this->versionKey($tenantId, $fresh->public_id));

            if (is_numeric($held) && (int) $held > $fresh->version) {
                $json = Redis::get($this->key($tenantId, $fresh->public_id));
                $decoded = is_string($json) ? json_decode($json, true) : null;

                if (is_array($decoded)) {
                    return $decoded;                                   // a newer snapshot already landed
                }
            }

            $ttl = self::ttlFor($fresh);
            Redis::setex($this->key($tenantId, $fresh->public_id), $ttl, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            Redis::setex($this->versionKey($tenantId, $fresh->public_id), $ttl, (string) $fresh->version);

            return $state;
        });

        return $state;
    }

    public function forget(SessionInstance $s): void
    {
        $tenantId = $this->tenantId();
        Redis::del($this->key($tenantId, $s->public_id), $this->versionKey($tenantId, $s->public_id));
    }

    public static function ttlFor(SessionInstance $s): int
    {
        return max(self::TTL_MIN, $s->planned_end_at->addHours(2)->getTimestamp() - now()->getTimestamp());
    }

    /**
     * serials.estimated_call_at for slips/SMS (REALTIME.md §14.1): one statement over the active serials whose cached
     * value moved by a minute or more. Not a clinical write (a cache column), hence the bulk UPDATE.
     *
     * Called ONLY from `queue:refresh-eta` (once a minute per running session), never from the after-commit rebuild:
     * a multi-row UPDATE of `serials` on the hot path takes row locks that deadlock against the serial engine's
     * concurrent allocations, and it rewrites tuples the engine's tests read in heap order.
     *
     * @param  array<string, mixed>  $state
     * @return int rows whose cached estimate moved
     */
    public function refreshEtaCache(SessionInstance $session, array $state): int
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $state['serials'] ?? [];

        if ($rows === []) {
            return 0;
        }

        $byPublicId = [];

        foreach ($rows as $row) {
            $byPublicId[(string) $row['id']] = $row['eta'];
        }

        $cached = DB::table('serials')
            ->where('session_instance_id', $session->id)
            ->whereIn('status', SerialStatus::activeValues())
            ->whereIn('public_id', array_keys($byPublicId))
            ->get(['id', 'public_id', 'estimated_call_at']);

        $changed = [];

        foreach ($cached as $row) {
            $fresh = $byPublicId[(string) $row->public_id] ?? null;
            $freshTs = is_string($fresh) ? strtotime($fresh) : null;
            $cachedTs = is_string($row->estimated_call_at) ? strtotime($row->estimated_call_at) : null;

            if ($freshTs === $cachedTs || ($freshTs !== null && $cachedTs !== null && abs($freshTs - $cachedTs) < 60)) {
                continue;
            }

            $changed[(int) $row->id] = $fresh;
        }

        if ($changed === []) {
            return 0;
        }

        ksort($changed);                                   // ascending serials.id — the lock order SERIAL_ENGINE §4.2 ends on

        $values = [];
        $bindings = [];

        foreach ($changed as $id => $eta) {
            $values[] = '(?::bigint, ?::timestamptz)';
            $bindings[] = $id;
            $bindings[] = $eta;
        }

        // A cache column must never make a live allocation wait, let alone deadlock with it: a short lock_timeout
        // turns any contention into 55P03 (lock_not_available), which is swallowed — the next minute redoes the row.
        return (int) rescue(fn () => DB::transaction(function () use ($values, $bindings): int {
            DB::statement("set local lock_timeout = '".self::LOCK_TIMEOUT_MS."ms'");

            return DB::update(
                'WITH targets AS (SELECT * FROM (VALUES '.implode(', ', $values).') AS v(id, eta) ORDER BY 1) '
                .'UPDATE serials s SET estimated_call_at = t.eta FROM targets t WHERE s.id = t.id',
                $bindings,
            );
        }), 0, report: false);
    }

    private function tenantId(): int
    {
        return Tenancy::id() ?? throw new TenancyNotInitialized(self::class);
    }
}
