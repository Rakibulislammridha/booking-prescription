<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use Illuminate\Support\Facades\DB;

/**
 * The seven session_instances.*_count columns are recalculated from serials, never incremented, and every
 * recalculation bumps `version` — the queue ETag (SERIAL_ENGINE §6.4). Runs inside the caller's transaction after
 * the serial row lock; the UPDATE itself takes the session row lock.
 */
final class CountsRecalculator
{
    /** Full recount + version bump; returns the new version. */
    public static function run(int $sessionInstanceId): int
    {
        $row = DB::selectOne(<<<'SQL'
            UPDATE session_instances s SET
              booked_count = c.booked, checked_in_count = c.checked_in, in_consultation_count = c.in_consultation,
              completed_count = c.completed, no_show_count = c.no_show, cancelled_count = c.cancelled, postponed_count = c.postponed,
              version = s.version + 1, updated_at = now()
            FROM (SELECT count(*) FILTER (WHERE status = 'booked')          AS booked,
                         count(*) FILTER (WHERE status = 'checked_in')      AS checked_in,
                         count(*) FILTER (WHERE status = 'in_consultation') AS in_consultation,
                         count(*) FILTER (WHERE status = 'completed')       AS completed,
                         count(*) FILTER (WHERE status = 'no_show')         AS no_show,
                         count(*) FILTER (WHERE status = 'cancelled')       AS cancelled,
                         count(*) FILTER (WHERE status = 'postponed')       AS postponed
                  FROM serials WHERE session_instance_id = :sid) c
            WHERE s.id = :sid2
            RETURNING s.version
            SQL, ['sid' => $sessionInstanceId, 'sid2' => $sessionInstanceId]);

        return (int) ($row->version ?? 0);
    }

    /** Reorders, priority inserts, delay/extend/pause and pool changes run only the version bump. */
    public static function bumpVersion(int $sessionInstanceId): int
    {
        $row = DB::selectOne('UPDATE session_instances SET version = version + 1, updated_at = now() WHERE id = :sid RETURNING version', ['sid' => $sessionInstanceId]);

        return (int) ($row->version ?? 0);
    }
}
