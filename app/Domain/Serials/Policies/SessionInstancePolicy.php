<?php

declare(strict_types=1);

namespace App\Domain\Serials\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;

/**
 * Session lifecycle authorisation (SERIAL_ENGINE §2.5, §3.4, §3.6): Start/Close — Doctor (own) or Reception;
 * Pause/Resume — Doctor (own) or Hospital Admin; Cancel — Hospital Admin or Doctor (own); Delay — queue.delay.broadcast;
 * Extend — serials.capacity.extend (receptionist limit enforced by the action); Split/ReleaseOnline — serials.split.adjust.
 */
final class SessionInstancePolicy
{
    public function view(User $user, SessionInstance $session): bool
    {
        return $user->is_active;
    }

    public function issue(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::SerialsIssueCounter->value) || $this->ownsAsDoctor($user, $session);
    }

    /** `queue.call-next`, and a doctor only on their own session (drivesSession — the ChannelGuards::doctor rule). */
    public function callNext(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::QueueCallNext->value) && self::drivesSession($user, $session->doctor_id);
    }

    public function start(User $user, SessionInstance $session): bool
    {
        return $this->ownsAsDoctor($user, $session) || $user->hasRole(Role::Receptionist->value) || $user->hasRole(Role::HospitalAdmin->value);
    }

    public function pause(User $user, SessionInstance $session): bool
    {
        return $this->ownsAsDoctor($user, $session) || $user->hasRole(Role::HospitalAdmin->value);
    }

    public function close(User $user, SessionInstance $session): bool
    {
        return $this->start($user, $session);
    }

    public function cancel(User $user, SessionInstance $session): bool
    {
        return $this->ownsAsDoctor($user, $session) || $user->hasRole(Role::HospitalAdmin->value);
    }

    public function delay(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::QueueDelayBroadcast->value);
    }

    public function extend(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::SerialsCapacityExtend->value);
    }

    public function adjustSplit(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::SerialsSplitAdjust->value);
    }

    public function transferSession(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::SerialsTransfer->value);
    }

    /**
     * Who may drive a session's queue: anyone without a doctors row (an operator — the permission check is the
     * caller's), or the doctor whose session it is. Shared with SerialPolicy::call so a serial-level call and a
     * session-level call-next answer the same way.
     */
    public static function drivesSession(User $user, int $doctorId): bool
    {
        $own = $user->doctor()->value('id');

        return $own === null || (int) $own === $doctorId;
    }

    private function ownsAsDoctor(User $user, SessionInstance $session): bool
    {
        return $user->hasRole(Role::Doctor->value) && $user->doctor()->value('id') === $session->doctor_id;
    }
}
