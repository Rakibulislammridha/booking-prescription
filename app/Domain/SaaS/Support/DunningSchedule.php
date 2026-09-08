<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Support;

use Carbon\CarbonImmutable;

/**
 * The dunning ladder, in one place, as days relative to an invoice's `due_at`.
 *
 *   day 0   step 1  "your invoice is overdue"       — the subscription moves to `past_due`; the panel shows the
 *                                                     banner EnsureTenantIsActive already renders, nothing is lost
 *   day +3  step 2  "second reminder"
 *   day +7  step 3  "final notice — access will be suspended on <grace date>"
 *   day +10         auto-suspend                    — `grace_until`; the clinic sees the Suspended page and pays
 *
 * Ten days of grace is deliberate: a clinic that misses a bank transfer must not lose its front desk on a
 * Thursday afternoon, and every message before the deadline names the exact date access stops.
 *
 * These are constants rather than config because `config/saas.php` is a foundation-owned file and this module
 * does not have one yet; a super admin who needs a different ladder for one tenant extends `grace_until` on the
 * subscription, which the sweep honours.
 */
final class DunningSchedule
{
    /** Days after `due_at` at which reminder N (1-based) is sent. */
    public const STEPS = [0, 3, 7];

    /** Days after `due_at` at which an unpaid subscription is suspended. */
    public const GRACE_DAYS = 10;

    /** Days a platform invoice is payable before it is overdue. */
    public const NET_DAYS = 7;

    public static function steps(): int
    {
        return count(self::STEPS);
    }

    /** The highest step that is due at `$now` for an invoice with this `due_at`; 0 when none is yet. */
    public static function dueStep(CarbonImmutable $dueAt, CarbonImmutable $now): int
    {
        $step = 0;

        foreach (self::STEPS as $index => $days) {
            if ($now->greaterThanOrEqualTo($dueAt->addDays($days))) {
                $step = $index + 1;
            }
        }

        return $step;
    }

    public static function graceDeadline(CarbonImmutable $dueAt): CarbonImmutable
    {
        return $dueAt->addDays(self::GRACE_DAYS);
    }

    public static function isFinalNotice(int $step): bool
    {
        return $step >= self::steps();
    }
}
