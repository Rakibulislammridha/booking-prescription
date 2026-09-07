<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Contracts;

use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;

/**
 * The ONE contract every channel implements — SMS, WhatsApp, web push, email and IVR. A driver is a pure
 * transport: it receives a rendered OutboundMessage and returns a classified DeliveryResult. It never reads the
 * database, never touches tenancy, never throws for a provider error (that is a DeliveryResult), and is always
 * constructed with a GatewayConfig rather than credentials of its own.
 */
interface ChannelDriver
{
    public function channel(): NotificationChannel;

    /** `notification_logs.provider` — the gateway key actually used ('ssl_wireless', 'log', 'whatsapp_cloud' …). */
    public function provider(): string;

    public function send(OutboundMessage $message): DeliveryResult;
}
