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
 */
final readonly class IssuedPrescriptionRef
{
    public function __construct(
        public ?string $publicId = null,
        public ?string $verificationCode = null,
        public ?int $version = null,
    ) {}

    public static function none(): self
    {
        return new self;
    }

    public function issued(): bool
    {
        return $this->publicId !== null;
    }

    /** @return array{public_id: string, verification_code: string|null, version: int}|null */
    public function toArray(): ?array
    {
        if ($this->publicId === null) {
            return null;
        }

        return [
            'public_id' => $this->publicId,
            'verification_code' => $this->verificationCode,
            'version' => (int) $this->version,
        ];
    }
}
