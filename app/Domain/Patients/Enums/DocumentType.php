<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum DocumentType: string
{
    case LabReport = 'lab_report';
    case Imaging = 'imaging';
    case ExternalPrescription = 'external_prescription';
    case DischargeSummary = 'discharge_summary';
    case Identity = 'identity';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
