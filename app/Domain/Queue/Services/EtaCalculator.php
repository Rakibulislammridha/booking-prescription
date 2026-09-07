<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Services\ConsultAverage;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ETA from the running average of actual consultation times (SERIAL_ENGINE.md §13). The EMA itself lives in the
 * Serials module's ConsultAverage (CompleteConsultation's transaction must not depend on this module); this class
 * delegates to it and owns the per-serial estimate:
 *
 *   base = running ? now : paused ? null : max(now, planned_start_at + delay_minutes)
 *   rem  = now_serving ? max(0, avg - (now - now_serving.called_at)) : 0
 *   eta  = base + rem + (checked_in ahead + round(booked ahead × serial.expected_show_rate)) × avg   (slot mode: ≥ slot_start_at)
 */
final class EtaCalculator
{
    public const ALPHA = ConsultAverage::ALPHA;

    public const MIN_SAMPLES = ConsultAverage::MIN_SAMPLES;

    public const CLAMP = ConsultAverage::CLAMP;

    public const DEFAULT_SHOW_RATE = 0.8;

    public function __construct(private readonly Settings $settings) {}

    /** Called inside CompleteConsultation's transaction (delegates to the Serials EMA). */
    public function updateAverage(SessionInstance $s, Serial $completed): int
    {
        return ConsultAverage::updateAverage($s, $completed);
    }

    public function confidence(SessionInstance $s): string
    {
        return ConsultAverage::confidence($s->consult_samples);
    }

    /** Weight of booked-not-arrived serials (tenant setting serial.expected_show_rate, default 0.8). */
    public function showRate(): float
    {
        $rate = $this->settings->get('serial.expected_show_rate');

        return is_numeric($rate) ? max(0.0, min(1.0, (float) $rate)) : self::DEFAULT_SHOW_RATE;
    }

    /**
     * ETA per active serial; input is the ordered active list (position asc), output keyed by serial id
     * (CarbonImmutable or null when suppressed — paused session, or the serial is the one in consultation).
     *
     * @param  Collection<int, Serial>  $active
     * @return array<int, CarbonImmutable|null>
     */
    public function estimate(SessionInstance $s, Collection $active, CarbonImmutable $now, ?float $showRate = null): array
    {
        $showRate ??= $this->showRate();
        $avg = max(1, $s->avg_consult_seconds);
        $base = self::base($s, $now);
        $nowServing = $s->now_serving_serial_id === null ? null : $active->first(fn (Serial $x) => $x->id === $s->now_serving_serial_id);
        $remaining = 0;

        if ($nowServing !== null && $nowServing->called_at !== null) {
            $remaining = max(0, $avg - max(0, $now->getTimestamp() - $nowServing->called_at->getTimestamp()));
        }

        $checkedInAhead = 0;
        $bookedAhead = 0;
        $out = [];

        foreach ($active as $serial) {
            if ($serial->status === SerialStatus::InConsultation) {
                $out[$serial->id] = null;

                continue;
            }

            if ($base === null) {
                $out[$serial->id] = null;
            } else {
                $eta = $base->addSeconds(self::offsetSeconds($avg, $remaining, $checkedInAhead, $bookedAhead, $showRate));

                if ($serial->slot_start_at !== null && $eta->lt($serial->slot_start_at)) {
                    $eta = $serial->slot_start_at;
                }

                $out[$serial->id] = $eta;
            }

            if ($serial->status === SerialStatus::CheckedIn) {
                $checkedInAhead++;
            } elseif ($serial->status === SerialStatus::Booked) {
                $bookedAhead++;
            }
        }

        return $out;
    }

    /**
     * The pure kernel of the estimate (SERIAL_ENGINE §13): seconds from `base` until this serial is called —
     * the rest of the current consultation plus one average per serial ahead, with `booked` (not yet arrived)
     * serials weighted by the expected show rate.
     */
    public static function offsetSeconds(int $avg, int $remainingOfCurrent, int $checkedInAhead, int $bookedAhead, float $showRate): int
    {
        return max(0, $remainingOfCurrent) + ($checkedInAhead + (int) round($bookedAhead * $showRate)) * max(1, $avg);
    }

    /** The instant the estimate counts from; null while paused (ETA suppressed, the UI shows "paused"). */
    public static function base(SessionInstance $s, CarbonImmutable $now): ?CarbonImmutable
    {
        return match ($s->status) {
            SessionStatus::Running => $now,
            SessionStatus::Paused => null,
            default => $now->max($s->expectedStartAt()),
        };
    }

    /** Presentation rule (§13): rounded up to 5 minutes and never earlier than now + 1 min. */
    public static function displayed(CarbonImmutable $eta, CarbonImmutable $now): CarbonImmutable
    {
        $floor = $now->addMinute();
        $candidate = $eta->lt($floor) ? $floor : $eta;
        $seconds = $candidate->getTimestamp();
        $rounded = (int) (ceil($seconds / 300) * 300);

        return CarbonImmutable::createFromTimestamp($rounded, $candidate->getTimezone());
    }
}
