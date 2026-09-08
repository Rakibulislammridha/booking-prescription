<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Enums;

/**
 * Who a minted token is for. Code-only (like Prescription's SafetyStage): it is the `role` key inside
 * `telemedicine_sessions.participants` jsonb, a JSON vocabulary with no CHECK of its own (SCHEMA Appendix A).
 *
 * The role is the whole point of token scoping: a patient token may publish its own camera and subscribe, and
 * nothing else — no recording, no room administration, no kicking the doctor out of their own consultation.
 */
enum ParticipantRole: string
{
    case Doctor = 'doctor';
    case Patient = 'patient';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    public function isModerator(): bool
    {
        return $this === self::Doctor;
    }

    /** Recording is a clinical act: only the doctor's token may ever carry it. */
    public function mayRecord(): bool
    {
        return $this === self::Doctor;
    }

    /** Identity prefix inside the provider room — a stable, non-guessable participant id. */
    public function identityFor(string $publicId): string
    {
        return $this->value.'-'.$publicId;
    }
}
