<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;

/** appointments.channel — the five booking channels of BRIEF §5.C plus telemedicine and offline replay. */
enum BookingChannel: string
{
    case Online = 'online';
    case Phone = 'phone';
    case Counter = 'counter';
    case Walkin = 'walkin';
    case Kiosk = 'kiosk';
    case Followup = 'followup';
    case Telemedicine = 'telemedicine';
    case Offline = 'offline';

    /** The serials.source a channel books with (SERIAL_ENGINE §3.2). */
    public function serialSource(): SerialSource
    {
        return match ($this) {
            self::Online, self::Telemedicine => SerialSource::Online,
            self::Phone, self::Counter => SerialSource::Counter,
            self::Walkin => SerialSource::Walkin,
            self::Kiosk => SerialSource::Kiosk,
            self::Followup => SerialSource::Followup,
            self::Offline => SerialSource::Offline,
        };
    }

    /** Pool per channel: online never consumes counter numbers; follow-up depends on who acts (§3.2). */
    public function pool(bool $staffActor): SerialPool
    {
        return $this->serialSource()->defaultPool($staffActor);
    }

    public function isSelfService(): bool
    {
        return in_array($this, [self::Online, self::Kiosk, self::Telemedicine], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
