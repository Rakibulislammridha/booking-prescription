<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\SaaS\Exceptions\PlatformGatewayNotConfigured;
use App\Models\Central\SuperAdmin;

/**
 * "Send a test to me" on the Platform notifications page, through the REAL drivers: email through the platform
 * mailer (the same identity and shell every owner mail uses), SMS through the driver built from the platform's
 * `sms.*` gateway — exactly what a tenant with no gateway of its own would send with. Nothing is queued and no
 * tenant ledger is touched; the attempt lands in the platform's own outbound log.
 */
final class PlatformTestSender
{
    public function __construct(
        private readonly PlatformMailer $mailer,
        private readonly PlatformSmsGateway $gateway,
        private readonly DriverFactory $drivers,
        private readonly PlatformMessageLog $log,
    ) {}

    /** @return array{status: string, provider: string, error: string|null} */
    public function email(SuperAdmin $admin, string $address, ?string $message): array
    {
        $subject = (string) __('super.notifications.test.email_subject', ['name' => $this->mailer->identity()['name']]);
        $body = ($message !== null && trim($message) !== '' ? trim($message) : (string) __('super.notifications.test.email_body'))
            ."\n\n".(string) __('super.notifications.test.sent_by', ['name' => $admin->name]);

        $sent = $this->mailer->toAddress($address, $admin->name, app()->getLocale(), $subject, $body, null, 'test', null, $admin->id);

        return ['status' => $sent ? 'sent' : 'failed', 'provider' => 'mail', 'error' => null];
    }

    /** @return array{status: string, provider: string, error: string|null} */
    public function sms(SuperAdmin $admin, string $mobile, ?string $message): array
    {
        $config = $this->gateway->configFor(NotificationChannel::Sms);

        if ($config === null) {
            throw new PlatformGatewayNotConfigured;
        }

        $driver = $this->drivers->fromConfig($config);
        $body = $message !== null && trim($message) !== '' ? trim($message) : (string) __('super.notifications.test.sms_body');

        $result = $driver->send(new OutboundMessage(
            channel: NotificationChannel::Sms,
            event: NotificationEvent::Otp,
            recipient: $mobile,
            body: $body,
            subject: null,
            locale: app()->getLocale(),
            payload: ['test' => true],
        ));

        $this->log->record('sms', 'test', $mobile, null, app()->getLocale(), self::status($result), $driver->provider(), $result->errorCode, null, $admin->id);

        return ['status' => self::status($result), 'provider' => $driver->provider(), 'error' => $result->errorCode];
    }

    private static function status(DeliveryResult $result): string
    {
        return $result->isSuccess() ? 'sent' : ($result->permanent ? 'rejected' : 'failed');
    }
}
