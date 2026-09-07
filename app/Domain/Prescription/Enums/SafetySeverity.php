<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

/** Code-only (no column): alert grading (PRESCRIPTION.md §5.2). */
enum SafetySeverity: string
{
    case Info = 'info';
    case Warning = 'warning';
    case Critical = 'critical';

    /** critical > warning > info */
    public function rank(): int
    {
        return match ($this) {
            self::Critical => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }
}
