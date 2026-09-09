<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

enum SerialStatus: string
{
    case Booked = 'booked';
    case CheckedIn = 'checked_in';
    case InConsultation = 'in_consultation';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';

    /** completed | cancelled | postponed (SERIAL_ENGINE §6). */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::Postponed], true);
    }

    /**
     * checked_in | in_consultation — the patient is physically in the clinic (SERIAL_ENGINE §6: "mark arrived" and
     * "check in" are the same transition). The only states in which anything clinical — vitals, an encounter —
     * can be done to them at the desk.
     */
    public function isPresent(): bool
    {
        return in_array($this, [self::CheckedIn, self::InConsultation], true);
    }

    /** booked | checked_in | in_consultation — the serials listed in the queue (I-POSITION applies to these). */
    public function isActive(): bool
    {
        return in_array($this, [self::Booked, self::CheckedIn, self::InConsultation], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return array<int, string> */
    public static function activeValues(): array
    {
        return [self::Booked->value, self::CheckedIn->value, self::InConsultation->value];
    }

    /** @return array<int, string> */
    public static function nonTerminalValues(): array
    {
        return [self::Booked->value, self::CheckedIn->value, self::InConsultation->value, self::NoShow->value];
    }
}
