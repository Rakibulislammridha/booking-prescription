<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Billing;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Subscriptions\StartDunning;
use App\Domain\SaaS\Actions\Subscriptions\SuspendTenant;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;

/**
 * "Run dunning now for this clinic" — one clinic's slice of exactly what `saas:dun` does: the same candidate
 * set, the same ladder arithmetic, the same two Actions (`StartDunning`, `SuspendTenant`), in the same order.
 * Nothing here can do something the scheduled sweep would not have done an hour later; it only does it now,
 * under an operator's name, and writes that name to `audit_logs_central`.
 *
 * Idempotent for the same reason the sweep is: `dunning_step` is the ratchet and suspension skips an already
 * suspended tenant, so pressing the button twice sends nothing twice.
 */
final class RunDunningForTenant
{
    public function __construct(
        private readonly StartDunning $dunning,
        private readonly SuspendTenant $suspend,
        private readonly CentralAudit $audit,
    ) {}

    /** @return array{notices: int, suspended: bool, invoices: array<int, string>} */
    public function handle(Tenant $tenant, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $notices = 0;
        $suspended = false;
        $touched = [];

        $invoices = SubscriptionInvoice::query()
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', [SubscriptionInvoiceStatus::Issued->value, SubscriptionInvoiceStatus::Overdue->value])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', $now)
            ->orderBy('id')
            ->get();

        foreach ($invoices as $invoice) {
            $dueAt = $invoice->getAttribute('due_at');

            if (! $dueAt instanceof CarbonImmutable) {
                continue;
            }

            $step = DunningSchedule::dueStep($dueAt, $now);

            if ($step > (int) $invoice->getAttribute('dunning_step')) {
                $this->dunning->handle($invoice, $now);
                $notices++;
                $touched[] = $invoice->number;
            }

            $tenant->refresh();

            if ($now->greaterThanOrEqualTo(DunningSchedule::graceDeadline($dueAt))
                && $tenant->status !== TenantStatus::Suspended
                && $tenant->status !== TenantStatus::Cancelled) {
                $this->suspend->handle($tenant, 'saas.suspension.unpaid', $invoice->id, true);
                $suspended = true;
                $touched[] = $invoice->number;
            }
        }

        $this->audit->record(CentralAuditAction::Update, $tenant, $tenant, null, [
            'dunning_run' => 'manual',
            'notices' => $notices,
            'suspended' => $suspended,
            'invoices' => array_values(array_unique($touched)),
        ]);

        return ['notices' => $notices, 'suspended' => $suspended, 'invoices' => array_values(array_unique($touched))];
    }
}
