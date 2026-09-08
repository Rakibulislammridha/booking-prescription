<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Data;

use App\Domain\Telemedicine\Enums\ParticipantRole;
use Carbon\CarbonImmutable;

/**
 * A verified provider callback, normalised across drivers. Anything the driver could not authenticate never
 * becomes one of these — `VideoProvider::verifyWebhook()` returns null instead.
 */
final readonly class ProviderWebhookEvent
{
    public const PARTICIPANT_JOINED = 'participant_joined';

    public const PARTICIPANT_LEFT = 'participant_left';

    public const ROOM_FINISHED = 'room_finished';

    public const RECORDING_STARTED = 'recording_started';

    public const RECORDING_FINISHED = 'recording_finished';

    /** @param  array<string, mixed>  $raw */
    public function __construct(
        public string $type,
        public string $roomName,
        public ?ParticipantRole $role = null,
        public ?string $identity = null,
        public ?CarbonImmutable $occurredAt = null,
        public ?string $sid = null,
        public ?string $recordingPath = null,
        public array $raw = [],
    ) {}

    public function isParticipantEvent(): bool
    {
        return $this->type === self::PARTICIPANT_JOINED || $this->type === self::PARTICIPANT_LEFT;
    }
}
