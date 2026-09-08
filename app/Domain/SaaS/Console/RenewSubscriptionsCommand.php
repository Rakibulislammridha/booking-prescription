<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Actions\Subscriptions\EndTrial;
use App\Domain\SaaS\Actions\Subscriptions\RenewSubscription;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Models\Central\Subscription;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `saas:renew-subscriptions` — the daily clock of the billing state machine, and CENTRAL work by construction:
 * it reads and writes only `public.*` and refuses to start inside a tenant, because a control-plane sweep that
 * ran with a tenant search path could silently bill from the wrong context.
 *
 * Two jobs, in one pass, because they are the same question asked of different statuses:
 *   · a `trialing` subscription whose trial has ended → convert (free) or issue the first invoice (paid);
 *   · an `active` subscription whose period has ended → issue the next invoice and roll the window forward.
 *
 * Each subscription is handled in its own try/catch: one clinic with broken data must not stop the platform's
 * billing run.
 */
final class RenewSubscriptionsCommand extends Command
{
    protected $signature = 'saas:renew-subscriptions {--dry-run : List what would happen and change nothing}';

    protected $description = 'End due trials and issue the next period invoice for renewing subscriptions';

    public function handle(EndTrial $endTrial, RenewSubscription $renew): int
    {
        if (Tenancy::check()) {
            $this->components->error('saas:renew-subscriptions is central work and must not run inside a tenant.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $dry = (bool) $this->option('dry-run');
        $trials = 0;
        $renewals = 0;

        Subscription::query()
            ->with(['plan', 'tenant'])
            ->where('status', SubscriptionStatus::Trialing->value)
            ->whereNotNull('trial_ends_at')
            ->where('trial_ends_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($endTrial, $now, $dry, &$trials): void {
                foreach ($subscriptions as $subscription) {
                    $this->components->twoColumnDetail('trial ended', $subscription->tenant->slug);

                    if (! $dry) {
                        $this->guard(fn () => $endTrial->handle($subscription, $now), $subscription->id);
                    }

                    $trials++;
                }
            });

        Subscription::query()
            ->with(['plan', 'tenant'])
            ->where('status', SubscriptionStatus::Active->value)
            ->where('current_period_end', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($renew, $now, $dry, &$renewals): void {
                foreach ($subscriptions as $subscription) {
                    $this->components->twoColumnDetail('period ended', $subscription->tenant->slug);

                    if (! $dry) {
                        $this->guard(fn () => $renew->handle($subscription, $now), $subscription->id);
                    }

                    $renewals++;
                }
            });

        $this->components->info(sprintf('%d trial(s) ended, %d subscription(s) renewed%s.', $trials, $renewals, $dry ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    private function guard(callable $work, int $subscriptionId): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('saas.renew.failed', ['subscription_id' => $subscriptionId, 'error' => $e->getMessage()]);
            $this->components->warn("subscription {$subscriptionId}: ".$e->getMessage());
        }
    }
}
