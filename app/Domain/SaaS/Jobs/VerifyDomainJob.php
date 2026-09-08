<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Jobs;

use App\Domain\SaaS\Actions\Domains\VerifyCustomDomain;
use App\Models\Central\Domain;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A CENTRAL job: it carries no tenant and must not initialise one — `public.domains` is a control-plane table and
 * the check is a DNS lookup. It deliberately does NOT use `TenantAware`.
 *
 * DNS is eventually consistent and a customer usually creates the record a minute after asking us to check, so
 * the job retries on a long ladder rather than failing the first time it sees no record.
 */
final class VerifyDomainJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public int $timeout = 30;

    public function __construct(public readonly int $domainId, public readonly bool $demoteOnFailure = true)
    {
        $this->onQueue('default');
    }

    /** @return array<int, int> 30 s → 2 min → 10 min → 30 min */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(VerifyCustomDomain $verify): void
    {
        $domain = Domain::query()->find($this->domainId);

        if ($domain === null) {
            return;
        }

        $verify->handle($domain, $this->demoteOnFailure);
    }
}
