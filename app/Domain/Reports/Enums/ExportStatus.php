<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

enum ExportStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Ready = 'ready';
    case Failed = 'failed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
