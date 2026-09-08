<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\Catalog\Events\CatalogReconciliationCompleted;
use App\Domain\SaaS\Services\PlatformMailer;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * ARCHITECTURE §5.4: the nightly soft-reference scan feeds a super-console tile, and emails when it found
 * orphans — a prescription pointing at a molecule the catalogue no longer has is a clinical problem, not a data
 * hygiene one, so it does not wait for someone to open a dashboard.
 *
 * The mail goes through the same `PlatformMailer` as every other platform message; the recipient is modelled as
 * a pseudo-tenant carrying the operator's address, so there is still exactly one mail path in this module.
 */
final class NotifyCatalogReconciliation
{
    public function __construct(private readonly PlatformMailer $mailer) {}

    public function handle(CatalogReconciliationCompleted $event): void
    {
        Log::info('saas.catalog.reconciliation', [
            'run_id' => $event->runId, 'tenants' => $event->tenants, 'orphans' => $event->orphans, 'inactive' => $event->inactive,
        ]);

        if ($event->orphans === 0) {
            return;
        }

        foreach (SuperAdmin::query()->where('is_active', true)->get() as $admin) {
            $recipient = new Tenant;
            $recipient->forceFill(['name' => $admin->name, 'owner_name' => $admin->name, 'owner_email' => $admin->email, 'locale' => 'en']);

            $this->mailer->toOwner($recipient, 'saas.mail.reconciliation.subject', 'saas.mail.reconciliation.body', [
                'orphans' => $event->orphans,
                'inactive' => $event->inactive,
                'tenants' => $event->tenants,
                'run_id' => $event->runId,
            ]);
        }
    }
}
