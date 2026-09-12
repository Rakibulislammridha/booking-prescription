<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * "Is there an issued prescription to print for this encounter, and which one?" — the Prescription module's answer
 * for a surface that must not read `prescriptions` itself (IssuedPrescriptionQuery, the reception board).
 *
 * Deliberately just the handle: the public id the output routes bind on, the verification code and the version.
 * The desk prints the sheet (BRIEF §5.G.4 — printed at the desk too); it never needs the snapshot, and this shape
 * cannot carry it, so nothing clinical reaches the board JSON or the device cache through it.
 *
 * Plus one fact ABOUT the handle rather than about the medicine: `printed` — has this sheet ever come off a
 * printer (`prescriptions.printed_count > 0`, which PrintController bumps on every rendered print)? Issuing a
 * prescription completes the serial, and the board's default view is the active rows, so without this the patient
 * standing at the counter waiting for their paper would vanish behind "Show all" at the exact moment the desk
 * needs them. The board keeps an issued-but-never-printed row in the default view and the rule clears itself the
 * moment the sheet is printed — no timer, no window (shared/offline/board.ts `isAwaitingPrint`). A count would say
 * more than the desk asked; the question is binary, so the answer is.
 */
final readonly class IssuedPrescriptionRef
{
    public function __construct(
        public ?string $publicId = null,
        public ?string $verificationCode = null,
        public ?int $version = null,
        public bool $printed = false,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function issued(): bool
    {
        return $this->publicId !== null;
    }

    /** @return array{public_id: string, verification_code: string|null, version: int, printed: bool}|null */
    public function toArray(): ?array
    {
        if ($this->publicId === null) {
            return null;
        }

        return [
            'public_id' => $this->publicId,
            'verification_code' => $this->verificationCode,
            'version' => (int) $this->version,
            'printed' => $this->printed,
        ];
    }
}
