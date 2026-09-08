<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Enums;

/** `telemedicine_rooms.provider` (SCHEMA §3.8, Appendix A). The three services BRIEF §5.K names. */
enum TelemedicineProvider: string
{
    case Agora = 'agora';
    case Livekit = 'livekit';
    case Jitsi = 'jitsi';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** A driver that mints its own JWT rather than calling a REST API for a token. */
    public function mintsJwt(): bool
    {
        return $this !== self::Agora;
    }
}
