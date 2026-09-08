<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Actions\Domains\VerifyCustomDomain;
use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Models\Central\Domain;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * `saas:verify-domains` — the DNS sweep behind `domains (verification_status, last_checked_at)`.
 *
 * Two populations, two policies:
 *   · pending / failed rows younger than 14 days are retried every run, because a customer who has just created
 *     the TXT record should not have to press anything;
 *   · verified rows are re-checked at most monthly and are NEVER demoted by the sweep — a registrar's outage or
 *     our own resolver failing must not take a live clinic's booking site off the air. The failure is logged and
 *     surfaced in the super console, where a human can re-verify deliberately.
 */
final class VerifyDomainsCommand extends Command
{
    protected $signature = 'saas:verify-domains {--all : Include verified rows regardless of when they were last checked}';

    protected $description = 'Re-check the DNS TXT proof for pending, failed and stale custom domains';

    public function handle(VerifyCustomDomain $verify): int
    {
        if (Tenancy::check()) {
            $this->components->error('saas:verify-domains is central work and must not run inside a tenant.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $checked = 0;
        $verified = 0;

        Domain::query()
            ->where('type', DomainType::Custom->value)
            ->where(function ($q) use ($now): void {
                $q->where(fn ($w) => $w->whereIn('verification_status', [DomainVerificationStatus::Pending->value, DomainVerificationStatus::Failed->value])
                    ->where('created_at', '>=', $now->subDays(14)))
                    ->orWhere(fn ($w) => $w->where('verification_status', DomainVerificationStatus::Verified->value)
                        ->where(fn ($s) => $s->whereNull('last_checked_at')->orWhere('last_checked_at', '<=', $now->subDays(30))));
            })
            ->when($this->option('all'), fn ($q) => $q->orWhere('type', DomainType::Custom->value))
            ->orderBy('id')
            ->chunkById(100, function ($domains) use ($verify, &$checked, &$verified): void {
                foreach ($domains as $domain) {
                    $wasVerified = $domain->verification_status === DomainVerificationStatus::Verified;

                    try {
                        $result = $verify->handle($domain, demoteOnFailure: ! $wasVerified);
                        $checked++;
                        $result->verified && $verified++;
                        $this->components->twoColumnDetail($domain->domain, $result->verified ? '<fg=green>verified</>' : '<fg=yellow>'.$result->reason.'</>');
                    } catch (Throwable $e) {
                        $this->components->warn($domain->domain.': '.$e->getMessage());
                    }
                }
            });

        $this->components->info("Checked {$checked} domain(s), {$verified} verified.");

        return self::SUCCESS;
    }
}
