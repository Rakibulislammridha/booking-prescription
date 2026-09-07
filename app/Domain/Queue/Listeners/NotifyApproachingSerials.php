<?php

declare(strict_types=1);

namespace App\Domain\Queue\Listeners;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Queue\Events\SerialApproaching;
use App\Domain\Serials\Events\SerialCalled;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

/**
 * The "3 ahead" trigger (REALTIME.md §7), queued on `notifications`. ONE statement decides and stamps: the dedupe IS
 * the `t3_notified_at IS NULL` predicate inside the `UPDATE … RETURNING`, so concurrent calls, reorders and
 * reinstates can never notify a patient twice. `ahead` may be < notify_ahead when no-shows collapse the line — the
 * event carries the actual count and the SMS text says it.
 *
 * This module only emits `App\Domain\Queue\Events\SerialApproaching`; the Notifications module renders it.
 */
final class NotifyApproachingSerials implements ShouldQueue
{
    use InteractsWithQueue, TenantAware;

    public string $queue = 'notifications';

    public int $tries = 3;

    public function __construct(private readonly Settings $settings)
    {
        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function handle(SerialCalled $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $position = (int) ($event->serial['position'] ?? 0);
        $notifyAhead = $this->notifyAhead();

        foreach (self::stamp($event->sessionInstanceId, $position, $notifyAhead) as $row) {
            SerialApproaching::dispatch(
                (int) $row->id,
                (string) $row->public_id,
                $event->sessionInstanceId,
                $event->sessionPublicId,
                (string) $row->display_code,
                (int) $row->ahead,
                $row->patient_id === null ? null : (int) $row->patient_id,
            );
        }
    }

    public function notifyAhead(): int
    {
        $value = $this->settings->get('queue.notify_ahead');

        return is_numeric($value) ? max(0, (int) $value) : 3;
    }

    /**
     * `ahead` = waiting serials strictly between now_serving and this one; the first in line has 0 ahead.
     *
     * @return array<int, object{id: int|string, public_id: string, display_code: string, patient_id: int|string|null, ahead: int|string}>
     */
    public static function stamp(int $sessionInstanceId, int $nowServingPosition, int $notifyAhead): array
    {
        /** @var array<int, object{id: int|string, public_id: string, display_code: string, patient_id: int|string|null, ahead: int|string}> $rows */
        $rows = DB::select(<<<'SQL'
            WITH waiting AS (
              SELECT id, position, status, t3_notified_at,
                     ROW_NUMBER() OVER (ORDER BY position) - 1 AS ahead
              FROM serials
              WHERE session_instance_id = :sid AND status IN ('booked','checked_in') AND position > :pos
            )
            UPDATE serials s SET t3_notified_at = now()
            FROM waiting w
            WHERE s.id = w.id AND w.ahead <= :ahead AND s.t3_notified_at IS NULL
            RETURNING s.id, s.public_id, s.display_code, s.patient_id, w.ahead
            SQL, ['sid' => $sessionInstanceId, 'pos' => $nowServingPosition, 'ahead' => $notifyAhead]);

        return $rows;
    }
}
