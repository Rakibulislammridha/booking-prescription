<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

/**
 * serial_events.type (SCHEMA §3.3). SERIAL_ENGINE's dotted names map here per its §19.7
 * (serial.allocated → booked, serial.number_skipped → number_skipped, pool.online_released → online_released …).
 */
enum SerialEventType: string
{
    case Booked = 'booked';
    case CheckedIn = 'checked_in';
    case Called = 'called';
    case ConsultationStarted = 'consultation_started';
    case Completed = 'completed';
    case NoShow = 'no_show';
    case Reinstated = 'reinstated';
    case ReinstatedAfterCancel = 'reinstated_after_cancel';
    case Cancelled = 'cancelled';
    case Postponed = 'postponed';
    case Reordered = 'reordered';
    case PriorityChanged = 'priority_changed';
    case Skipped = 'skipped';
    case TransferredOut = 'transferred_out';
    case TransferredIn = 'transferred_in';
    case PatientAssigned = 'patient_assigned';
    case NoteAdded = 'note_added';
    case Printed = 'printed';
    case RecordedPostClose = 'recorded_post_close';
    case NumberSkipped = 'number_skipped';
    case Renormalised = 'renormalised';
    case CapacityExtended = 'capacity_extended';
    case OnlineReleased = 'online_released';
    case SplitChanged = 'split_changed';
    case BlockLeased = 'block_leased';
    case BlockReleased = 'block_released';
    case BlockRevoked = 'block_revoked';
    case Delayed = 'delayed';
    case VoidLocal = 'void_local';

    /** Session-level rows (serial_id NULL) — mirrors the CHECK in the migration. */
    public function isSessionLevel(): bool
    {
        return in_array($this, [
            self::NumberSkipped, self::Renormalised, self::CapacityExtended, self::OnlineReleased, self::SplitChanged,
            self::BlockLeased, self::BlockReleased, self::BlockRevoked, self::Delayed, self::VoidLocal,
        ], true);
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
