<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\Tenant;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Throwable;

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
 * Bodies come from `resources/lang/{en,bn}.json` under `saas.mail.*`, in the tenant's own locale — or from the
 * console's rewrite of them (`PlatformMailTemplates`). The From identity is the platform's (`mail.from_*`
 * settings, defaulting to config('mail.from')), and every attempt lands in the outbound ledger
 * (`PlatformMessageLog`), sent or not.
 */
final class PlatformMailer
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly PlatformSettings $settings,
        private readonly PlatformMailTemplates $templates,
        private readonly PlatformMessageLog $log,
    ) {}

    /** @param  array<string, string|int>  $replace */
    public function toOwner(Tenant $tenant, string $subjectKey, string $bodyKey, array $replace = [], ?string $link = null, ?int $superAdminId = null): bool
    {
        $address = trim($tenant->owner_email);
        $locale = $tenant->locale->value;
        $replace = ['clinic' => $tenant->name] + $replace;
        $subject = $this->templates->render($subjectKey, $replace, $locale);
        $body = $this->templates->render($bodyKey, $replace, $locale);
        $kind = PlatformMailTemplates::templateOf($subjectKey) ?? self::kindOf($subjectKey);

        return $this->toAddress($address, $tenant->owner_name, $locale, $subject, $body, $link, $kind, $tenant->exists ? $tenant->id : null, $superAdminId);
    }

    /**
     * One rendered mail to one address (the console's test send, the reconciliation notice to an operator). The
     * body is plain text; it is escaped here and newlines become paragraphs in the shell.
     */
    public function toAddress(string $address, ?string $name, string $locale, string $subject, string $body, ?string $link = null, string $kind = 'other', ?int $tenantId = null, ?int $superAdminId = null): bool
    {
        $address = trim($address);

        if ($address === '' || filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $this->log->record('email', $kind, $address, $subject, $locale, 'rejected', 'mail', 'invalid_email', $tenantId, $superAdminId);

            return false;
        }

        $identity = $this->identity();
        $html = view('mail.notification', ['subject' => $subject, 'body' => e($body), 'locale' => $locale, 'link' => $link])->render();

        try {
            $this->mailer->html($html, function (Message $mail) use ($address, $name, $subject, $identity): void {
                $mail->to($address, $name)->subject($subject)->from($identity['address'], $identity['name']);

                if ($identity['reply_to'] !== '') {
                    $mail->replyTo($identity['reply_to']);
                }
            });
        } catch (Throwable $e) {
            // A bounced dunning mail must not abort the sweep — but it must not vanish either.
            $this->log->record('email', $kind, $address, $subject, $locale, 'failed', 'mail', $e->getMessage(), $tenantId, $superAdminId);

            return false;
        }

        $this->log->record('email', $kind, $address, $subject, $locale, 'sent', 'mail', null, $tenantId, $superAdminId);

        return true;
    }

    /**
     * The platform's outgoing identity: the `mail.from_*` settings, whose registry defaults are config('mail.from').
     *
     * @return array{name: string, address: string, reply_to: string}
     */
    public function identity(): array
    {
        $address = trim((string) $this->settings->get(PlatformSettingsRegistry::MAIL_FROM_ADDRESS));
        $name = trim((string) $this->settings->get(PlatformSettingsRegistry::MAIL_FROM_NAME));

        return [
            'name' => $name !== '' ? $name : (string) config('mail.from.name', ''),
            'address' => $address !== '' ? $address : (string) config('mail.from.address', ''),
            'reply_to' => trim((string) $this->settings->get(PlatformSettingsRegistry::MAIL_REPLY_TO)),
        ];
    }

    /** `saas.mail.receipt.subject` → `receipt`; anything else is `other`. */
    private static function kindOf(string $langKey): string
    {
        return preg_match('/^saas\.mail\.([a-z_]+)\./', $langKey, $m) === 1 ? $m[1] : 'other';
    }
}
