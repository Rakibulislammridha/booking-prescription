<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Console;

use App\Domain\SaaS\Actions\Subscriptions\StartDunning;
use App\Domain\SaaS\Actions\Subscriptions\SuspendTenant;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\SubscriptionInvoice;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * `saas:dun` — walk every overdue platform invoice up the ladder, and suspend when the grace runs out.
 *
 * Order matters: dunning first (so a clinic that is about to be suspended has certainly had its final notice),
 * suspension second, and only for invoices whose `due_at` is more than `GRACE_DAYS` old. Suspension is idempotent
 * — an already-suspended tenant is skipped — so running the sweep hourly is safe and the ladder still emits each
 * reminder exactly once (`subscription_invoices.dunning_step` is the ratchet).
 */
final class DunSubscriptionsCommand extends Command
{
    protected $signature = 'saas:dun {--dry-run : Report the ladder and suspensions without acting}';

    protected $description = 'Advance dunning on overdue platform invoices and auto-suspend after the grace period';

    public function handle(StartDunning $dunning, SuspendTenant $suspend): int
    {
        if (Tenancy::check()) {
            $this->components->error('saas:dun is central work and must not run inside a tenant.');

            return self::FAILURE;
        }

        $now = CarbonImmutable::now();
        $dry = (bool) $this->option('dry-run');
        $dunned = 0;
        $suspended = 0;

        SubscriptionInvoice::query()
            ->with(['tenant', 'subscription'])
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->orderBy('id')
            ->chunkById(100, function ($invoices) use ($dunning, $suspend, $now, $dry, &$dunned, &$suspended): void {
                foreach ($invoices as $invoice) {
                    $dueAt = $invoice->getAttribute('due_at');
                    $tenant = $invoice->tenant;

                    if (! $dueAt instanceof CarbonImmutable || $tenant === null) {
                        continue;
                    }

                    $step = DunningSchedule::dueStep($dueAt, $now);
                    $grace = DunningSchedule::graceDeadline($dueAt);

                    if ($step > (int) $invoice->getAttribute('dunning_step')) {
                        $this->components->twoColumnDetail($invoice->number.' · '.$tenant->slug, 'dunning step '.$step);
                        $dunned++;

                        if (! $dry) {
                            $this->guard(fn () => $dunning->handle($invoice, $now), $invoice->number);
                        }
                    }

                    if ($now->greaterThanOrEqualTo($grace) && $tenant->status !== TenantStatus::Suspended && $tenant->status !== TenantStatus::Cancelled) {
                        $this->components->twoColumnDetail($invoice->number.' · '.$tenant->slug, '<fg=red>suspend</>');
                        $suspended++;

                        if (! $dry) {
                            $this->guard(fn () => $suspend->handle($tenant, 'saas.suspension.unpaid', $invoice->id, true), $invoice->number);
                        }
                    }
                }
            });

        $this->components->info(sprintf('%d dunning step(s), %d suspension(s)%s.', $dunned, $suspended, $dry ? ' (dry run)' : ''));

        return self::SUCCESS;
    }

    private function guard(callable $work, string $reference): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            Log::error('saas.dun.failed', ['invoice' => $reference, 'error' => $e->getMessage()]);
            $this->components->warn("{$reference}: ".$e->getMessage());
        }
    }
}
