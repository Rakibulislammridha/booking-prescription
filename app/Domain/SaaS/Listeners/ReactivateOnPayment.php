<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\SaaS\Actions\Subscriptions\ReactivateTenant;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Events\SubscriptionPaymentReceived;
use App\Domain\SaaS\Services\PlatformMailer;
use App\Domain\Shared\Money;
use App\Models\Central\Tenant;

/**
 * Paying brings a clinic back. One listener, so it does not matter whether the money arrived from a gateway
 * callback, a super admin recording a bank transfer, or a console command — `ReactivateTenant` re-checks the
 * arrears itself, so a payment that clears only part of what is owed correctly leaves the clinic past_due.
 */
final class ReactivateOnPayment
{
    public function __construct(
        private readonly ReactivateTenant $reactivate,
        private readonly PlatformMailer $mailer,
    ) {}

    public function handle(SubscriptionPaymentReceived $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        $this->mailer->toOwner($tenant, 'saas.mail.receipt.subject', 'saas.mail.receipt.body', [
            'amount' => Money::bdt($event->amountPaisa)->format(),
            'method' => $event->method,
        ]);

        if (in_array($tenant->status, [TenantStatus::PastDue, TenantStatus::Suspended], true)) {
            $this->reactivate->handle($tenant, 'saas.reactivate.payment');
        }
    }
}
