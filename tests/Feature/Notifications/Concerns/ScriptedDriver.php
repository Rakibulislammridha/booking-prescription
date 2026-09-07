<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications\Concerns;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;

/** A scripted driver: answers the queued results in order, then repeats the last one. */
final class ScriptedDriver implements ChannelDriver
{
    /** @var array<int, OutboundMessage> */
    public array $received = [];

    /** @param  array<int, DeliveryResult>  $script */
    public function __construct(private array $script) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Sms;
    }

    public function provider(): string
    {
        return 'scripted';
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $this->received[] = $message;

        return count($this->script) > 1 ? array_shift($this->script) : $this->script[0];
    }
}
