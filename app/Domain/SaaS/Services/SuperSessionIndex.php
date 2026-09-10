<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\Clinic\Data\StaffSessionData;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

/**
 * The `super` guard's twin of `App\Domain\Clinic\Services\StaffSessionIndex` (ARCHITECTURE §6.1): which browsers
 * an operator is signed in on, and the ability to end them — from the Profile screen for one's own devices, and
 * from the Admins screen the moment an account is deactivated. Without it, deactivating an operator only bit on
 * their NEXT request (`EnsureSuperAdminIsActive`), and a browser that never made one stayed a live console.
 *
 * Redis hash per operator, `bp:super_sessions:{admin}` → session id ⇒ {ip, ua, login_at, last_seen_at}, carrying
 * the session lifetime as its TTL. Entries are written by `CompletesSuperLogin` (after the post-login
 * regenerate, so the id is the one the browser will present) and refreshed by `EnsureSuperAdminIsActive` at most
 * once a minute; a logout drops its own entry (`ForgetSuperSession`). The DTO is the clinic one on purpose: the
 * shape is identical, and the browser addresses a session by the same opaque `ref`, never by the id.
 *
 * There is no "remember me" on the super guard (B4), but `super_admins.remember_token` still exists and Laravel's
 * recaller would honour it if a cookie ever appeared — so revocation rotates it exactly as the staff index does.
 */
final class SuperSessionIndex
{
    private const PREFIX = 'bp:super_sessions';

    public function __construct(private readonly RedisFactory $redis) {}

    public function remember(SuperAdmin $admin, string $sessionId, ?string $ip, ?string $userAgent, ?int $loginAt = null): void
    {
        $now = CarbonImmutable::now()->getTimestamp();

        $this->write($admin, $sessionId, [
            'ip' => $ip,
            'ua' => $userAgent === null ? null : mb_substr($userAgent, 0, 512),
            'login_at' => $loginAt ?? $now,
            'last_seen_at' => $now,
        ]);
    }

    public function has(SuperAdmin $admin, string $sessionId): bool
    {
        return $this->read($admin, $sessionId) !== null;
    }

    /** Refresh `last_seen_at` for a session already in the index; a session that is not there is not re-created. */
    public function touch(SuperAdmin $admin, string $sessionId): void
    {
        $entry = $this->read($admin, $sessionId);

        if ($entry === null) {
            return;
        }

        $entry['last_seen_at'] = CarbonImmutable::now()->getTimestamp();
        $this->write($admin, $sessionId, $entry);
    }

    /**
     * Every live session of this operator, newest activity first; entries whose payload has expired are pruned.
     *
     * @return array<int, StaffSessionData>
     */
    public function all(SuperAdmin $admin, ?string $currentSessionId = null): array
    {
        $currentSessionId ??= $this->currentSessionId();
        $out = [];

        foreach ($this->hash($admin) as $sessionId => $raw) {
            $entry = $this->decode($raw);

            if ($entry === null || ($sessionId !== $currentSessionId && ! $this->payloadExists($sessionId))) {
                $this->forget($admin, $sessionId);

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

    public function findByRef(SuperAdmin $admin, string $ref): ?StaffSessionData
    {
        foreach ($this->all($admin) as $session) {
            if (hash_equals(StaffSessionData::ref($session->id), $ref)) {
                return $session;
            }
        }

        return null;
    }

    /** Destroy one session, drop it from the index and rotate the remember token. False when it was already gone. */
    public function revoke(SuperAdmin $admin, string $sessionId): bool
    {
        $revoked = $this->destroy($admin, $sessionId);

        if ($revoked) {
            $this->rotateRememberToken($admin);
        }

        return $revoked;
    }

    /** Throw every other browser off. Returns how many sessions were destroyed. */
    public function revokeOthers(SuperAdmin $admin, string $keepSessionId): int
    {
        $revoked = 0;

        foreach (array_keys($this->hash($admin)) as $sessionId) {
            if ($sessionId === $keepSessionId) {
                continue;
            }

            $revoked += $this->destroy($admin, $sessionId) ? 1 : 0;
        }

        if ($revoked > 0) {
            $this->rotateRememberToken($admin);
        }

        return $revoked;
    }

    /**
     * End every session of this operator — deactivation, a 2FA reset, a password set by a link. The remember
     * token is rotated UNCONDITIONALLY, even when the index holds nothing: a recaller cookie that outlived its
     * session would otherwise be untouched.
     */
    public function revokeAll(SuperAdmin $admin): int
    {
        $revoked = $this->revokeOthers($admin, '');
        $this->rotateRememberToken($admin);

        return $revoked;
    }

    public function rotateRememberToken(SuperAdmin $admin): void
    {
        $admin->setRememberToken(Str::random(60));
        $admin->save();
    }

    public function forget(SuperAdmin $admin, string $sessionId): void
    {
        $this->connection()->hdel($this->key($admin), $sessionId);
    }

    /** The session this request is running under, or null outside one (a job, a console command). */
    public function currentSessionId(): ?string
    {
        $request = request();

        return $request->hasSession() ? $request->session()->getId() : null;
    }

    private function destroy(SuperAdmin $admin, string $sessionId): bool
    {
        if ($this->read($admin, $sessionId) === null) {
            return false;
        }

        Session::getHandler()->destroy($sessionId);
        $this->forget($admin, $sessionId);

        return true;
    }

    /** @param array<string, mixed> $entry */
    private function write(SuperAdmin $admin, string $sessionId, array $entry): void
    {
        $key = $this->key($admin);
        $connection = $this->connection();
        $connection->hset($key, $sessionId, (string) json_encode($entry, JSON_THROW_ON_ERROR));
        $connection->expire($key, max(60, (int) config('session.lifetime') * 60));
    }

    /** @return array<string, mixed>|null */
    private function read(SuperAdmin $admin, string $sessionId): ?array
    {
        $raw = $this->connection()->hget($this->key($admin), $sessionId);

        return is_string($raw) ? $this->decode($raw) : null;
    }

    /** @return array<string, string> */
    private function hash(SuperAdmin $admin): array
    {
        /** @var array<string, string> $all */
        $all = (array) $this->connection()->hgetall($this->key($admin));

        return $all;
    }

    /** @return array<string, mixed>|null */
    private function decode(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** The CURRENT session is never asked: its payload is only written when the request terminates. */
    private function payloadExists(string $sessionId): bool
    {
        return Session::getHandler()->read($sessionId) !== '';
    }

    private function connection(): Connection
    {
        return $this->redis->connection();
    }

    private function key(SuperAdmin $admin): string
    {
        return self::PREFIX.':'.$admin->getKey();
    }
}
