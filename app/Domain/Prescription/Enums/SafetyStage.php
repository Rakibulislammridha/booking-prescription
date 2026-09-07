<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

/** Code-only (no column): where the safety pipeline runs (PRESCRIPTION.md §5.1). */
enum SafetyStage: string
{
    case Draft = 'draft';
    case Issue = 'issue';
}
