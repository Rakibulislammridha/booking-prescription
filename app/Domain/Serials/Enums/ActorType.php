<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

use App\Domain\Shared\Actor;

/** serial_events.actor_type. */
enum ActorType: string
{
    case User = 'user';
    case Patient = 'patient';
    case Device = 'device';
    case System = 'system';

    public static function fromActor(Actor $actor): self
    {
        return match (true) {
            $actor->userId !== null => self::User,
            $actor->deviceId !== null => self::Device,
            $actor->patientId !== null => self::Patient,
            default => self::System,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
