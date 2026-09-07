<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Serials\Services\SerialTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Auto no-show (SERIAL_ENGINE §8): on every call of serial S, every `booked` serial positioned before S gets
 * passed_count + 1 (one UPDATE … RETURNING); rows reaching session_instances.auto_noshow_after become no_show through
 * SerialTransition with actor system — never before planned_start_at + delay + queue.auto_noshow_grace_minutes,
 * never for checked_in serials (they are present), and never when N = 0. Runs inside the call transaction.
 */
final class ApplyAutoNoShow
{
    public function __construct(
        private readonly SerialTransition $transition,
        private readonly Settings $settings,
    ) {}

    /** @return array<int, Serial> the serials auto no-showed by this sweep */
    public function sweep(SessionInstance $session, Serial $called, Actor $actor): array
    {
        $passed = DB::select(
            "UPDATE serials SET passed_count = passed_count + 1, updated_at = now() WHERE session_instance_id = :sid AND status = 'booked' AND position < :pos RETURNING id, passed_count",
            ['sid' => $session->id, 'pos' => $called->position],
        );

        $n = $session->auto_noshow_after;

        if ($n <= 0 || $passed === []) {
            return [];
        }

        $grace = max(0, (int) $this->settings->get('queue.auto_noshow_grace_minutes'));

        if (now()->lt($session->expectedStartAt()->addMinutes($grace))) {
            return [];
        }

        $noShowed = [];

        foreach ($passed as $row) {
            if ((int) $row->passed_count < $n) {
                continue;
            }

            $noShowed[] = $this->apply((int) $row->id, $session, (int) $row->passed_count);
        }

        return $noShowed;
    }

    /**
     * Slot-mode variant (§10): a booked serial whose slot_start_at + slot_minutes has passed without check-in.
     *
     * @return array<int, Serial>
     */
    public function sweepSlots(SessionInstance $session): array
    {
        if ($session->slot_minutes === null || $session->slot_minutes <= 0 || $session->auto_noshow_after <= 0) {
            return [];
        }

        $cutoff = now()->subMinutes($session->slot_minutes);
        $ids = DB::table('serials')
            ->where('session_instance_id', $session->id)
            ->where('status', SerialStatus::Booked->value)
            ->whereNotNull('slot_start_at')
            ->where('slot_start_at', '<=', $cutoff)
            ->pluck('id');

        $out = [];

        foreach ($ids as $id) {
            $out[] = $this->apply((int) $id, $session, $session->auto_noshow_after);
        }

        return $out;
    }

    private function apply(int $serialId, SessionInstance $session, int $passed): Serial
    {
        /** @var Serial $serial */
        $serial = Serial::query()->findOrFail($serialId);
        $noShow = $this->transition->apply($serial, SerialStatus::NoShow, Actor::system(), ['session' => $session, 'no_show_reason' => 'auto', 'passed' => $passed]);
        SerialNoShow::dispatch($noShow, $session, 'auto');

        return $noShow;
    }
}
