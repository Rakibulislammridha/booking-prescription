<?php

declare(strict_types=1);

namespace App\Domain\Serials\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;

/**
 * Session lifecycle authorisation (SERIAL_ENGINE §2.5, §3.4, §3.6): Start/Close — Doctor (own) or Reception;
 * Pause/Resume — Doctor (own) or Hospital Admin; Cancel — Hospital Admin or Doctor (own); Delay — queue.delay.broadcast;
 * Extend — serials.capacity.extend (receptionist limit enforced by the action); Split/ReleaseOnline — serials.split.adjust.
 *
 * EVERY ability here ends in DoctorScope, `view` and the lifecycle alike. `view` needs it because GET
 * /panel/sessions/{id} answers with the session AND every serial on it, so "any active user" would hand a compounder
 * a colleague's whole queue one guessable public id at a time. The lifecycle methods were first left unscoped on the
 * argument that "the compounder holds none of these permissions" — true of the role, false of an ACCOUNT: grant one
 * user compounder AND receptionist (or accountant) and they read a scoped board, then start, pause, close, cancel,
 * extend, re-split or transfer any doctor's session from the other role's permission. Holding the compounder role
 * always restricts, so the conjunct is unconditional. It changes nothing for anyone else: DoctorScope::doctorIds()
 * answers null for them, and allows() is then true. Every caller is one `authorize()` on one bound session, so this
 * is one indexed read per request, never per row.
 */
final class SessionInstancePolicy
{
    public function __construct(private readonly DoctorScope $scope) {}

    public function view(User $user, SessionInstance $session): bool
    {
        return $user->is_active && $this->scope->allows($user, $session->doctor_id);
    }

    public function issue(User $user, SessionInstance $session): bool
    {
        return ($user->can(Permission::SerialsIssueCounter->value) || $this->ownsAsDoctor($user, $session))
            && $this->scope->allows($user, $session->doctor_id);
    }

    /** `queue.call-next`, and a doctor only on their own session (drivesSession — the ChannelGuards::doctor rule). */
    public function callNext(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::QueueCallNext->value)
            && self::drivesSession($user, $session->doctor_id)
            && $this->scope->allows($user, $session->doctor_id);
    }

    public function start(User $user, SessionInstance $session): bool
    {
        return ($this->ownsAsDoctor($user, $session) || $user->hasRole(Role::Receptionist->value) || $user->hasRole(Role::HospitalAdmin->value))
            && $this->scope->allows($user, $session->doctor_id);
    }

    public function pause(User $user, SessionInstance $session): bool
    {
        return ($this->ownsAsDoctor($user, $session) || $user->hasRole(Role::HospitalAdmin->value))
            && $this->scope->allows($user, $session->doctor_id);
    }

    public function close(User $user, SessionInstance $session): bool
    {
        return $this->start($user, $session);
    }

    public function cancel(User $user, SessionInstance $session): bool
    {
        return ($this->ownsAsDoctor($user, $session) || $user->hasRole(Role::HospitalAdmin->value))
            && $this->scope->allows($user, $session->doctor_id);
    }

    public function delay(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::QueueDelayBroadcast->value) && $this->scope->allows($user, $session->doctor_id);
    }

    public function extend(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::SerialsCapacityExtend->value) && $this->scope->allows($user, $session->doctor_id);
    }

    public function adjustSplit(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::SerialsSplitAdjust->value) && $this->scope->allows($user, $session->doctor_id);
    }

    public function transferSession(User $user, SessionInstance $session): bool
    {
        return $user->can(Permission::SerialsTransfer->value) && $this->scope->allows($user, $session->doctor_id);
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
