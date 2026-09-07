<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Drivers\Mail;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/**
 * Email through Laravel's mailer (no per-tenant SMTP row: SCHEMA gives `sms_gateway_settings` only the sms /
 * whatsapp / ivr channels, so mail is a platform transport with the tenant's name in the From).
 *
 * The body arrives already escaped for HTML (NotificationChannel::escaping()) and is wrapped in the minimal
 * Bangla-safe shell of `resources/views/mail/notification.blade.php`.
 */
final class MailerDriver implements ChannelDriver
{
    public function __construct(
        private readonly Mailer $mailer,
        private readonly string $fromAddress,
        private readonly string $fromName,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Email;
    }

    public function provider(): string
    {
        return 'mail';
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        if (filter_var($message->recipient, FILTER_VALIDATE_EMAIL) === false) {
            return DeliveryResult::rejected('invalid_email');
        }

        $started = microtime(true);
        $subject = $message->subject ?? __('notifications.email.default_subject', [], $message->locale);
        $html = view('mail.notification', ['body' => $message->body, 'subject' => $subject, 'locale' => $message->locale, 'link' => $this->link($message)])->render();

        try {
            $this->mailer->html($html, function (Message $mail) use ($message, $subject): void {
                $mail->to($message->recipient)->subject($subject)->from($this->fromAddress, $this->fromName);
            });
        } catch (TransportExceptionInterface $e) {
            return DeliveryResult::failed('smtp_transport', ['message' => $e->getMessage()], $this->elapsed($started));
        } catch (Throwable $e) {
            return DeliveryResult::failed('mailer', ['message' => $e->getMessage()], $this->elapsed($started));
        }

        return DeliveryResult::sent(null, ['mailer' => 'laravel'], $this->elapsed($started))
            ->withRequest(['to' => LogRecipient::mask($message->recipient), 'subject' => $subject]);
    }

    private function link(OutboundMessage $message): ?string
    {
        $link = $message->payload['link'] ?? null;

        return is_string($link) && $link !== '' ? $link : null;
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
