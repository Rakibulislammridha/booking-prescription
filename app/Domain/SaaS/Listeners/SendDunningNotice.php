<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\SaaS\Events\DunningNoticeDue;
use App\Domain\SaaS\Services\PlatformMailer;
use App\Domain\Shared\Money;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\URL;

/**
 * One rung of the ladder, one email to the clinic owner, in their own language.
 *
 * The pay link is a SIGNED central URL, not a panel link: a suspended tenant's panel answers 402, so a dunning
 * mail that pointed there would be a dead end at exactly the moment the customer wants to pay. Signed, because
 * the page names an amount and a clinic and must not be guessable from an invoice id.
 */
final class SendDunningNotice
{
    public function __construct(private readonly PlatformMailer $mailer) {}

    public function handle(DunningNoticeDue $event): void
    {
        $invoice = SubscriptionInvoice::query()->find($event->invoiceId);
        $tenant = Tenant::query()->find($event->tenantId);

        if ($invoice === null || $tenant === null) {
            return;
        }

        $link = URL::signedRoute('central.billing.invoice', ['invoice' => $invoice->public_id], now()->addDays(30));

        $this->mailer->toOwner(
            $tenant,
            $event->isFinalNotice ? 'saas.mail.dunning_final.subject' : 'saas.mail.dunning.subject',
            $event->isFinalNotice ? 'saas.mail.dunning_final.body' : 'saas.mail.dunning.body',
            [
                'number' => $invoice->number,
                'amount' => Money::bdt($invoice->total_paisa - (int) $invoice->getAttribute('paid_paisa'))->format(),
                'due' => $invoice->getAttribute('due_at')?->toFormattedDateString() ?? '—',
                'suspends_on' => CarbonImmutable::parse($event->suspendsOn)->toFormattedDateString(),
                'step' => $event->step,
            ],
            $link,
        );
    }
}
