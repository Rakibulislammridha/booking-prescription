<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Drivers\LogChannelDriver;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Services\SegmentCounter;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SmsGatewaySetting;

/**
 * "Send test message" from the gateway screen. It goes straight through the driver WITHOUT writing a
 * `notifications` row: a test is an operator action against a gateway, not a message to a patient, and it must not
 * pollute the outbound ledger, the segment meter or `usage_counters.sms_credits`.
 *
 * It is audited (`share` on the gateway row) with the masked destination, because "who sent what through the
 * clinic's paid SMS account" is exactly the sort of thing an admin needs to be able to reconstruct.
 */
final class SendTestMessage
{
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly SegmentCounter $segments,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(SmsGatewaySetting $gateway, string $recipient, string $body, Actor $actor): DeliveryResult
    {
        $driver = $this->drivers->fromConfig(GatewayConfig::fromModel($gateway));
        $count = $this->segments->count($body);

        $result = $driver->send(new OutboundMessage(
            channel: $gateway->channel,
            event: NotificationEvent::Otp,
            recipient: $recipient,
            body: $body,
            subject: null,
            locale: $count->isUnicode() ? 'bn' : 'en',
            payload: ['test' => true],
        ));

        $this->audit->record(AuditAction::Share, $gateway, null, null, [
            'test_message' => true,
            'channel' => $gateway->channel->value,
            'provider' => $driver->provider(),
            'recipient' => LogChannelDriver::mask($recipient),
            'segments' => $count->segments,
            'encoding' => $count->encoding,
            'status' => $result->status->value,
            'error_code' => $result->errorCode,
            'actor' => $actor->userId,
        ]);

        return $result;
    }
}
