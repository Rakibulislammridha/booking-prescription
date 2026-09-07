<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/**
 * The running average of SERIAL_ENGINE §13: an exponential moving average of the call-to-complete duration
 * (completed_at − called_at). SERIAL_ENGINE places `updateAverage()` on the Queue module's EtaCalculator; the EMA is
 * kept here so CompleteConsultation's transaction does not depend on that module — EtaCalculator delegates to it.
 * Pure: no container, no DB.
 */
final class ConsultAverage
{
    public const ALPHA = 0.25;        // ≈ 7-sample window

    public const MIN_SAMPLES = 3;     // below this the ETA confidence is 'low'

    public const CLAMP = [30, 1800];  // seconds; a sample outside is discarded (doctor forgot to press complete)

    /** Duration in seconds of a completed consultation, or null when the stamps are missing. */
    public static function sample(Serial $completed): ?int
    {
        if ($completed->called_at === null || $completed->completed_at === null) {
            return null;
        }

        return (int) abs($completed->completed_at->getTimestamp() - $completed->called_at->getTimestamp());
    }

    public static function accepts(?int $sample): bool
    {
        return $sample !== null && $sample >= self::CLAMP[0] && $sample <= self::CLAMP[1];
    }

    /** New avg_consult_seconds after folding in one sample (the current average when the sample is discarded). */
    public static function next(int $currentAverage, ?int $sample): int
    {
        if (! self::accepts($sample)) {
            return $currentAverage;
        }

        return (int) round(self::ALPHA * $sample + (1 - self::ALPHA) * $currentAverage);
    }

    /** SERIAL_ENGINE §13 `updateAverage(SessionInstance, Serial)`. */
    public static function updateAverage(SessionInstance $session, Serial $completed): int
    {
        return self::next($session->avg_consult_seconds, self::sample($completed));
    }

    public static function confidence(int $samples): string
    {
        return $samples >= self::MIN_SAMPLES ? 'normal' : 'low';
    }
}
