<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS\Concerns;

use Illuminate\Support\Facades\Redis;

/**
 * `SuperSessionIndex` lives in Redis, which no database transaction rolls back — and the super_admins sequence
 * restarts with every migrated test database, so an entry left behind by one test lands on the NEXT test's
 * operator. `keys()` answers with the connection prefix already applied and `del()` would apply it again, so the
 * prefix is stripped before the delete (the reason a naive `del(...keys())` silently deletes nothing).
 */
trait ClearsSuperSessions
{
    protected function clearSuperSessions(): void
    {
        $connection = Redis::connection();
        $prefix = (string) config('database.redis.options.prefix', '');
        /** @var array<int, string> $keys */
        $keys = (array) $connection->keys('bp:super_sessions:*');
        $bare = array_map(fn (string $key): string => $prefix !== '' && str_starts_with($key, $prefix) ? substr($key, strlen($prefix)) : $key, $keys);

        if ($bare !== []) {
            $connection->del(...$bare);
        }
    }
}
