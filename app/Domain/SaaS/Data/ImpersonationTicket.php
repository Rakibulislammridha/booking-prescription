<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use Carbon\CarbonImmutable;

/**
 * The one-time handoff a super admin is given. The plain token exists ONLY here and in the redirect URL — the
 * database stores its sha256 — so a leaked `impersonation_tokens` dump cannot be replayed into a clinic.
 */
final readonly class ImpersonationTicket
{
    public function __construct(
        public int $tokenId,
        public string $url,
        public CarbonImmutable $expiresAt,
        public int $userId,
        public string $userName,
        public string $tenantName,
    ) {}
}
