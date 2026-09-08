<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Data;

use App\Domain\Telemedicine\Enums\ParticipantRole;

/**
 * One token, for one participant, for one room, for a short while.
 *
 * The grants are derived from the ROLE and nothing else (`forRole()`): a controller cannot ask for a patient
 * token with recording rights, because it never gets to choose the grants. `TelemedicineTokenScopeTest` pins that.
 */
final readonly class TokenRequest
{
    public function __construct(
        public string $roomName,
        public ParticipantRole $role,
        public string $identity,
        public string $displayName,
        public int $ttlSeconds,
        public bool $canPublish,
        public bool $canSubscribe,
        public bool $canPublishData,
        public bool $canRecord,
        public bool $isModerator,
    ) {}

    /** The only constructor callers use: role in, grants out. */
    public static function forRole(string $roomName, ParticipantRole $role, string $identity, string $displayName, int $ttlSeconds, bool $recordingAllowed = false): self
    {
        return new self(
            roomName: $roomName,
            role: $role,
            identity: $identity,
            displayName: $displayName,
            ttlSeconds: max(30, $ttlSeconds),
            canPublish: true,
            canSubscribe: true,
            canPublishData: true,
            canRecord: $recordingAllowed && $role->mayRecord(),
            isModerator: $role->isModerator(),
        );
    }
}
