<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Jobs;

use App\Domain\SaaS\Services\PlatformMailer;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The set-password e-mail the console mints for a clinic user, on the `notifications` queue.
 *
 * Queued for the same reason every other platform mail is: an SMTP relay that takes twenty seconds must not hold
 * a support operator's request open for twenty seconds. The job carries PLAIN DATA — the address, the name, the
 * URL already built on the clinic's host by `TenantLinks` — and no tenant model, so it needs no tenancy on the
 * worker and cannot leak a schema across jobs. The link itself is the credential; this is only its delivery,
 * and the operator has the same link on screen whether or not the mail arrives.
 */
final class SendStaffSetPasswordLinkJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [10, 60, 300];

    public function __construct(
        public readonly int $tenantId,
        public readonly string $clinicName,
        public readonly string $address,
        public readonly string $name,
        public readonly string $locale,
        public readonly string $url,
        public readonly int $expiresMinutes,
        public readonly ?int $superAdminId = null,
    ) {
        $this->onQueue('notifications');
    }

    public function handle(PlatformMailer $mailer): void
    {
        $replace = ['name' => $this->name, 'clinic' => $this->clinicName, 'minutes' => $this->expiresMinutes, 'url' => $this->url];

        $mailer->toAddress(
            $this->address,
            $this->name,
            $this->locale,
            (string) __('super.tenants.mail.set_password.subject', $replace, $this->locale),
            (string) __('super.tenants.mail.set_password.body', $replace, $this->locale),
            $this->url,
            'set_password',
            $this->tenantId,
            $this->superAdminId,
        );
    }
}
