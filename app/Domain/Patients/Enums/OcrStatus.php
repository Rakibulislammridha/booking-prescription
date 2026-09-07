<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum OcrStatus: string
{
    case Pending = 'pending';
    case Done = 'done';
    case Failed = 'failed';
    case Skipped = 'skipped';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
