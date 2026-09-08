<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Models\Central\ImpersonationToken;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * `saas:prune-impersonation-tokens` — SCHEMA §2.17: "Rows older than 24 h are pruned."
 *
 * The tokens are dead after 60 seconds either way (consumed or expired), so keeping them is only useful for the
 * day's forensics; the durable record of who impersonated whom is `audit_logs_central`, which is append-only and
 * is never pruned.
 */
final class PruneImpersonationTokensCommand extends Command
{
    protected $signature = 'saas:prune-impersonation-tokens {--hours=24}';

    protected $description = 'Delete impersonation handoff tokens older than 24 hours';

    public function handle(): int
    {
        $cutoff = CarbonImmutable::now()->subHours(max(1, (int) $this->option('hours')));
        $deleted = ImpersonationToken::query()->where('created_at', '<', $cutoff)->delete();

        $this->components->info("Pruned {$deleted} impersonation token(s).");

        return self::SUCCESS;
    }
}
