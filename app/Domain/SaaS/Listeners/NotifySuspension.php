<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\SaaS\Events\TenantAutoSuspended;
use App\Domain\SaaS\Services\PlatformMailer;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\URL;

/** The clinic is now looking at the Suspended page; the email says why and carries the link that undoes it. */
final class NotifySuspension
{
    public function __construct(private readonly PlatformMailer $mailer) {}

    public function handle(TenantAutoSuspended $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);
        $invoice = SubscriptionInvoice::query()->find($event->invoiceId);

        if ($tenant === null || $invoice === null) {
            return;
        }

        $this->mailer->toOwner(
            $tenant,
            'saas.mail.suspended.subject',
            'saas.mail.suspended.body',
            ['number' => $invoice->number],
            URL::signedRoute('central.billing.invoice', ['invoice' => $invoice->public_id], now()->addDays(60)),
        );
    }
}
