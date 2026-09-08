<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\SaaS\Services\PlatformMailer;
use App\Domain\Tenancy\Events\TenantProvisioned;

/** ARCHITECTURE §5.4: SaaS consumes `TenantProvisioned`. The owner gets the panel URL and the trial end date. */
final class SendTenantWelcome
{
    public function __construct(private readonly PlatformMailer $mailer) {}

    public function handle(TenantProvisioned $event): void
    {
        $tenant = $event->tenant;

        $this->mailer->toOwner(
            $tenant,
            'saas.mail.welcome.subject',
            'saas.mail.welcome.body',
            [
                'owner' => $tenant->owner_name,
                'trial_ends' => $tenant->trial_ends_at?->toFormattedDateString() ?? '—',
                'url' => 'https://'.$tenant->primaryHost().'/panel',
            ],
            'https://'.$tenant->primaryHost().'/panel',
        );
    }
}
