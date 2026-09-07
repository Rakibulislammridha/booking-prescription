<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

enum Gender: string
{
    case Male = 'male';
    case Female = 'female';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
