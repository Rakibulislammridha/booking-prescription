<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Notifications;

use App\Models\Central\SuperAdmin;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

/**
 * "Set your password" for a platform operator — sent when an account is created without a password, and on
 * demand from the Admins screen for one who lost theirs. It is the super guard's password reset in all but name:
 * the token comes from the `super_admins` broker (`public.password_reset_tokens`, config/auth.php) and lands on
 * `super.password.set` on the super host.
 *
 * Rendered into the platform mail shell (`resources/views/mail/notification.blade.php`) like every other
 * platform → person email, and NOT through Laravel's default `ResetPassword` notification, whose URL builder is
 * registered for the tenant `users` broker and would send an operator to a clinic's panel.
 */
final class SuperSetPasswordLink extends Notification
{
    public function __construct(
        #[SensitiveParameter] private readonly string $token,
        private readonly int $expiresMinutes,
    ) {}

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function url(SuperAdmin $admin): string
    {
        return route('super.password.set', ['token' => $this->token, 'email' => $admin->email]);
    }

    public function toMail(SuperAdmin $notifiable): MailMessage
    {
        $subject = (string) __('super.admins.mail.set_password_subject');
        $body = (string) __('super.admins.mail.set_password_body', ['name' => $notifiable->name, 'minutes' => (string) $this->expiresMinutes]);
        $link = $this->url($notifiable);

        return (new MailMessage)
            ->subject($subject)
            ->view('mail.notification', ['subject' => $subject, 'body' => e($body), 'locale' => app()->getLocale(), 'link' => $link]);
    }
}
