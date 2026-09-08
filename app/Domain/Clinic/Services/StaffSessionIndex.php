<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Domain\Clinic\Data\StaffSessionData;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Session;

/**
 * The `sessions_by_user` index of ARCHITECTURE §6.1: which devices a staff account is logged in on, and the ability
 * to throw one off. `authenticateSessions()` is deliberately NOT enabled (it would force a re-login on every device
 * when a password changes), so this index is what makes "I left myself logged in at the front desk" recoverable.
 *
 * Redis hash per user, `bp:sessions_by_user:{tenant}:{user}` → session id ⇒ {ip, ua, login_at, last_seen_at}. The
 * hash carries the session lifetime as its TTL, so an abandoned index cannot outlive the sessions it describes.
 *
 * Revoking destroys the session payload through the session handler (whatever driver is configured), then drops the
 * index entry — in that order, so a crash between the two leaves a dead entry rather than a live session nobody can
 * see. `all()` drops entries whose payload is already gone, which is what a natural expiry looks like from here.
 */
final class StaffSessionIndex
{
    private const PREFIX = 'bp:sessions_by_user';

    public function __construct(private readonly RedisFactory $redis) {}

    public function remember(User $user, string $sessionId, ?string $ip, ?string $userAgent, ?int $loginAt = null): void
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $this->write($user, $sessionId, [
            'ip' => $ip,
            'ua' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'login_at' => $loginAt ?? $now,
            'last_seen_at' => $now,
        ]);
    }

    public function has(User $user, string $sessionId): bool
    {
        return $this->read($user, $sessionId) !== null;
    }

    /** Refresh `last_seen_at` for a session already in the index; a session that is not there is not re-created. */
    public function touch(User $user, string $sessionId): void
    {
        $entry = $this->read($user, $sessionId);

        if ($entry === null) {
            return;
        }

        $entry['last_seen_at'] = CarbonImmutable::now()->getTimestamp();
        $this->write($user, $sessionId, $entry);
    }

    /**
     * Every live session of this user, newest activity first. Entries whose session payload has expired are pruned
     * on read, so the screen never offers to revoke something that is already gone.
     *
     * @return array<int, StaffSessionData>
     */
    public function all(User $user, ?string $currentSessionId = null): array
    {
        $currentSessionId ??= $this->currentSessionId();
        $out = [];

        foreach ($this->hash($user) as $sessionId => $raw) {
            $entry = $this->decode($raw);

            if ($entry === null || ($sessionId !== $currentSessionId && ! $this->payloadExists($sessionId))) {
                $this->forget($user, $sessionId);

                continue;
            }

            $out[] = new StaffSessionData(
                id: $sessionId,
                ip: is_string($entry['ip'] ?? null) ? $entry['ip'] : null,
                userAgent: is_string($entry['ua'] ?? null) ? $entry['ua'] : null,
                loginAt: CarbonImmutable::createFromTimestamp((int) ($entry['login_at'] ?? 0)),
                lastSeenAt: CarbonImmutable::createFromTimestamp((int) ($entry['last_seen_at'] ?? 0)),
                isCurrent: $sessionId === $currentSessionId,
            );
        }

        usort($out, fn (StaffSessionData $a, StaffSessionData $b) => $b->lastSeenAt <=> $a->lastSeenAt);

        return $out;
    }

    /** Resolve the opaque `ref` the panel screen sends back to the session id it stands for. */
    public function findByRef(User $user, string $ref): ?StaffSessionData
    {
        foreach ($this->all($user) as $session) {
            if (hash_equals(StaffSessionData::ref($session->id), $ref)) {
                return $session;
            }
        }

        return null;
    }

    /** Destroy the session itself and drop it from the index. Returns false when it was already gone. */
    public function revoke(User $user, string $sessionId): bool
    {
        if ($this->read($user, $sessionId) === null) {
            return false;
        }

        Session::getHandler()->destroy($sessionId);
        $this->forget($user, $sessionId);

        return true;
    }

    /** Throw every other device off. Returns how many sessions were destroyed. */
    public function revokeOthers(User $user, string $keepSessionId): int
    {
        $revoked = 0;

        foreach (array_keys($this->hash($user)) as $sessionId) {
            if ($sessionId === $keepSessionId) {
                continue;
            }

            $revoked += $this->revoke($user, $sessionId) ? 1 : 0;
        }

        return $revoked;
    }

    /**
     * End every session of this user. Used when an account is deactivated: an account that can no longer log in
     * must not stay logged in on a desk somewhere.
     */
    public function revokeAll(User $user): int
    {
        return $this->revokeOthers($user, '');
    }

    public function forget(User $user, string $sessionId): void
    {
        $this->connection()->hdel($this->key($user), $sessionId);
    }

    /** The session this request is running under, or null outside one (a job, a console command). */
    public function currentSessionId(): ?string
    {
        $request = request();

        return $request->hasSession() ? $request->session()->getId() : null;
    }

    /** @param array<string, mixed> $entry */
    private function write(User $user, string $sessionId, array $entry): void
    {
        $key = $this->key($user);
        $connection = $this->connection();
        $connection->hset($key, $sessionId, (string) json_encode($entry, JSON_THROW_ON_ERROR));
        $connection->expire($key, $this->ttlSeconds());
    }

    /** @return array<string, mixed>|null */
    private function read(User $user, string $sessionId): ?array
    {
        $raw = $this->connection()->hget($this->key($user), $sessionId);

        return is_string($raw) ? $this->decode($raw) : null;
    }

    /** @return array<string, string> */
    private function hash(User $user): array
    {
        /** @var array<string, string> $all */
        $all = (array) $this->connection()->hgetall($this->key($user));

        return $all;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Whether the session payload still exists. The CURRENT session is never asked: its payload is only written
     * when the request terminates, so a brand-new session would prune itself out of its own device list.
     */
    private function payloadExists(string $sessionId): bool
    {
        return Session::getHandler()->read($sessionId) !== '';
    }

    private function connection(): Connection
    {
        return $this->redis->connection();
    }

    private function key(User $user): string
    {
        return self::PREFIX.':'.(Tenancy::id() ?? 'central').':'.$user->getKey();
    }

    private function ttlSeconds(): int
    {
        return max(60, (int) config('session.lifetime') * 60);
    }
}
