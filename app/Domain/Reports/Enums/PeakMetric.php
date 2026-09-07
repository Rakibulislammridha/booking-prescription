<?php

declare(strict_types=1);

namespace App\Domain\Reports\Enums;

/**
 * Which moment the heatmap counts. Staffing follows ARRIVALS (when people are physically at the desk), which is
 * why `checked_in_at` is the default; the other two answer different questions and are offered as a switch
 * rather than silently conflated with the first.
 */
enum PeakMetric: string
{
    case Arrivals = 'arrivals';
    case Bookings = 'bookings';
    case Consultations = 'consultations';

    /** The `serials` timestamp column this metric reads. */
    public function column(): string
    {
        return match ($this) {
            self::Arrivals => 'checked_in_at',
            self::Bookings => 'booked_at',
            self::Consultations => 'called_at',
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
