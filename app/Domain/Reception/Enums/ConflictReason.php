<?php

declare(strict_types=1);

namespace App\Domain\Reception\Enums;

/** offline_events.conflict_reason — the taxonomy of OFFLINE §8 (+ dependency_unresolved for `pending`). */
enum ConflictReason: string
{
    case DuplicatePatient = 'duplicate_patient';
    case PatientMismatch = 'patient_mismatch';
    case SerialAlreadyUsed = 'serial_already_used';
    case SessionClosed = 'session_closed';
    case StatusRegression = 'status_regression';
    case AlreadyPaid = 'already_paid';
    case DependencyUnresolved = 'dependency_unresolved';
    case BlockReleased = 'block_released';
    case UnknownSerial = 'unknown_serial';

    /**
     * The resolutions a receptionist may choose for this conflict (OFFLINE §8 cards).
     *
     * @return array<int, ConflictResolution>
     */
    public function resolutions(): array
    {
        return match ($this) {
            self::DuplicatePatient, self::PatientMismatch => [ConflictResolution::LinkPatient, ConflictResolution::FamilyMember],
            self::SerialAlreadyUsed, self::BlockReleased => [ConflictResolution::Reissue, ConflictResolution::Discard],
            self::SessionClosed => [ConflictResolution::MoveToSession, ConflictResolution::RecordInClosed, ConflictResolution::Discard],
            self::StatusRegression => [ConflictResolution::Reinstate, ConflictResolution::Discard],
            self::AlreadyPaid => [ConflictResolution::RefundCash, ConflictResolution::Credit, ConflictResolution::Discard],
            self::UnknownSerial => [ConflictResolution::Discard],
            self::DependencyUnresolved => [],
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
