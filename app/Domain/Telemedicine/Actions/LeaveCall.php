<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Events\ParticipantPresenceChanged;
use App\Domain\Telemedicine\Services\TelemedicineAuditor;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * A participant left — a tab closed, a phone rang, a train went into a tunnel. This does NOT end the call: a
 * dropped connection must be able to rejoin the same session row, which is exactly what makes the "connection
 * lost, rejoining" state honest rather than cosmetic. Only `EndCall` closes a session.
 */
final class LeaveCall
{
    public function __construct(private readonly TelemedicineAuditor $auditor) {}

    public function handle(TelemedicineRoom $room, ParticipantRole $role, Actor $actor): ?TelemedicineSession
    {
        $session = TelemedicineSession::query()
            ->where('telemedicine_room_id', $room->id)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            return null;
        }

        $changed = DB::transaction(function () use ($session, $role): bool {
            /** @var TelemedicineSession $locked */
            $locked = TelemedicineSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $index = $locked->presenceOf($role);

            if ($index === null) {
                return false;
            }

            $participants = $locked->participants;
            $participants[$index]['left_at'] = now()->toIso8601String();
            $locked->forceFill(['participants' => array_values($participants)])->save();
            $session->setAttribute('participants', $locked->participants);

            return true;
        });

        if (! $changed) {
            return $session;
        }

        $this->auditor->left($session, $room, $role);
        DB::afterCommit(fn () => ParticipantPresenceChanged::dispatch((int) Tenancy::id(), $room->id, $session->id, $role, false));

        return $session;
    }
}
