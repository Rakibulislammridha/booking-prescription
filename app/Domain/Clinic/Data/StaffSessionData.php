<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use Carbon\CarbonImmutable;

/**
 * One live staff session as the `sessions_by_user` index holds it (ARCHITECTURE §6.1). `id` is the Laravel session
 * id and is NEVER sent to the browser — the screen addresses a session by `ref`, a SHA-256 prefix of it, so a
 * listing of your own devices can never be replayed as a session cookie.
 */
final readonly class StaffSessionData
{
    public function __construct(
        public string $id,
        public ?string $ip,
        public ?string $userAgent,
        public CarbonImmutable $loginAt,
        public CarbonImmutable $lastSeenAt,
        public bool $isCurrent,
    ) {}

    public static function ref(string $sessionId): string
    {
        return mb_substr(hash('sha256', $sessionId), 0, 32);
    }

    /** A short human label ("Chrome on Windows"); the raw agent stays available for a support call. */
    public function device(): string
    {
        $agent = $this->userAgent ?? '';

        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') || str_contains($agent, 'Opera') => 'Opera',
            str_contains($agent, 'Chrome/') => 'Chrome',
            str_contains($agent, 'Firefox/') => 'Firefox',
            str_contains($agent, 'Safari/') => 'Safari',
            $agent === '' => 'Unknown',
            default => 'Browser',
        };

        $platform = match (true) {
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'iPhone') || str_contains($agent, 'iPad') => 'iOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Linux') => 'Linux',
            default => '',
        };

        return $platform === '' ? $browser : $browser.' · '.$platform;
    }

    /** @return array<string, mixed> the shape the panel screen renders; never carries the session id */
    public function toArray(): array
    {
        return [
            'ref' => self::ref($this->id),
            'ip' => $this->ip,
            'device' => $this->device(),
            'user_agent' => $this->userAgent,
            'login_at' => $this->loginAt->toIso8601String(),
            'last_seen_at' => $this->lastSeenAt->toIso8601String(),
            'is_current' => $this->isCurrent,
        ];
    }
}
