<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

enum AiSuggestionType: string
{
    case HistorySummary = 'history_summary';
    case Differential = 'differential';
    case Advice = 'advice';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
