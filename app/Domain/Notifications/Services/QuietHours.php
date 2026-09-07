<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Clinic\Services\Settings;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Clinic-local quiet hours (BRIEF §5.J "respect quiet hours if settings define them"). A non-urgent message
 * produced at 23:10 is not dropped — it is stored `scheduled` for the next opening, so the patient still gets it.
 *
 * The window is tenant configuration: `notifications.quiet_hours_{enabled,start,end}` in the settings registry
 * (SCHEMA Appendix B), edited on the clinic settings screen. Off by default — BRIEF §5.J asks to respect quiet
 * hours "if settings define them", and a clinic that has not chosen a window has not defined one. There is no
 * config fallback: a second source for the same window is how the two drift apart.
 */
final class QuietHours
{
    private const START_KEY = 'notifications.quiet_hours_start';

    private const END_KEY = 'notifications.quiet_hours_end';

    private const ENABLED_KEY = 'notifications.quiet_hours_enabled';

    public function __construct(private readonly Settings $settings) {}

    public function isEnabled(): bool
    {
        return (bool) $this->settings->get(self::ENABLED_KEY);
    }

    /** @return array{0: string, 1: string} `HH:MM` start and end, clinic-local */
    public function window(): array
    {
        return [(string) $this->settings->get(self::START_KEY), (string) $this->settings->get(self::END_KEY)];
    }

    /** $at is an instant; the comparison happens in the tenant's timezone. */
    public function isQuiet(?CarbonImmutable $at = null): bool
    {
        if (! $this->isEnabled()) {
            return false;
        }

        [$start, $end] = $this->window();

        if ($start === $end) {
            return false;
        }

        $local = ($at ?? CarbonImmutable::now())->setTimezone(Clock::timezone());
        $minutes = $local->hour * 60 + $local->minute;
        $from = self::minutes($start);
        $to = self::minutes($end);

        // A window that wraps past midnight (22:00 → 07:00) is quiet outside [end, start).
        return $from <= $to ? $minutes >= $from && $minutes < $to : $minutes >= $from || $minutes < $to;
    }

    /** The first instant after $at at which a non-urgent message may be sent. */
    public function nextOpening(?CarbonImmutable $at = null): CarbonImmutable
    {
        $at ??= CarbonImmutable::now();

        if (! $this->isQuiet($at)) {
            return $at;
        }

        [, $end] = $this->window();
        $local = $at->setTimezone(Clock::timezone());
        $opening = $local->startOfDay()->addMinutes(self::minutes($end));

        if ($opening <= $local) {
            $opening = $opening->addDay();
        }

        return $opening->setTimezone('UTC');
    }

    private static function minutes(string $hhmm): int
    {
        [$h, $m] = array_pad(array_map('intval', explode(':', $hhmm, 2)), 2, 0);

        return max(0, min(23, $h)) * 60 + max(0, min(59, $m));
    }
}
