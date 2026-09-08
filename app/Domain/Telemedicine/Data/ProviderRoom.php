<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Data;

/** What the provider answered when the room was created. `sid` is `telemedicine_sessions.provider_session_id`. */
final readonly class ProviderRoom
{
    public function __construct(
        public string $roomName,
        public ?string $sid = null,
        public ?string $url = null,
    ) {}
}
