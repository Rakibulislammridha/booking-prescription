<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Models\Central\Tenant;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

/**
 * Platform → clinic-owner email (welcome, invoice issued, dunning, suspension, reactivation).
 *
 * It is not a second notification system: it renders into the SAME shell the Notifications module's
 * `MailerDriver` uses (`resources/views/mail/notification.blade.php`) and sends through the SAME framework
 * mailer. What it deliberately does NOT do is go through `notifications` rows and `notification_templates` —
 * those live in the TENANT schema and are the clinic's own, editable, patient-facing templates. A dunning notice
 * is the platform talking to its customer about money; a clinic must not be able to rewrite or suppress it, and
 * a suspended tenant's schema must not have to be entered to tell them why.
 *
 * Bodies come from `resources/lang/{en,bn}.json` under `saas.mail.*`, in the tenant's own locale.
 */
final class PlatformMailer
{
    public function __construct(private readonly Mailer $mailer) {}

    /** @param  array<string, string|int>  $replace */
    public function toOwner(Tenant $tenant, string $subjectKey, string $bodyKey, array $replace = [], ?string $link = null): bool
    {
        $address = trim($tenant->owner_email);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $locale = $tenant->locale->value;
        $replace = ['clinic' => $tenant->name] + $replace;
        $subject = (string) __($subjectKey, $replace, $locale);
        $body = (string) __($bodyKey, $replace, $locale);

        $html = view('mail.notification', [
            'subject' => $subject,
            'body' => e($body),
            'locale' => $locale,
            'link' => $link,
        ])->render();

        try {
            $this->mailer->html($html, function (Message $mail) use ($address, $subject, $tenant): void {
                $mail->to($address, $tenant->owner_name)->subject($subject);
            });
        } catch (TransportExceptionInterface) {
            return false;                                             // a bounced dunning mail must not abort the sweep
        }

        return true;
    }
}
