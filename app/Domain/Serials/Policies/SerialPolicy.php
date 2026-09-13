<?php

declare(strict_types=1);

namespace App\Domain\Serials\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\BranchAccess;
use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\Serial;
use App\Models\Tenant\User;

/**
 * Serial-level authorisation per the "Who" column of SERIAL_ENGINE §6.
 *
 * EVERY row-level ability here ends in DoctorScope, not only the ones a compounder can reach. The first cut scoped
 * `view`, `recordVitals` and `checkIn` and left the queue-driving and number-editing moves on their permission or
 * role alone — which held only for as long as "compounder" was somebody's ONLY role. An account granted compounder
 * AND receptionist read a doctor-scoped board and could then call, complete, cancel, postpone, reorder, re-prioritise
 * or transfer any row on it, and got the full resource — patient block included — back in the response. The locked
 * rule is that holding the compounder role ALWAYS restricts, so the conjunct belongs on every ability; it costs
 * everyone else nothing, because DoctorScope::doctorIds() answers null for them and allows() is then true.
 *
 * The doctor of a serial is its session's (`session_instances.doctor_id`; serials carry no doctor of their own), and
 * the relation is READ, never `loadMissing`-ed — BoardBuilder hands each row its session with setRelation precisely
 * so a board of a few hundred serials stays one query. Adding the conjunct to the rest of the abilities does not
 * re-open the N+1 either: every caller is a single `authorize()`/`can()` on one bound model in a controller or a
 * FormRequest, and the per-row capability flags a board ships are plain permission strings
 * (`$user->can('serials.cancel')`), never a policy call inside a loop.
 */
final class SerialPolicy
{
    public function __construct(private readonly BranchAccess $branches, private readonly DoctorScope $scope) {}

    public function view(User $user, Serial $serial): bool
    {
        return $user->is_active && $this->scope->allows($user, $this->doctorOf($serial));
    }

    /**
     * The desk recording vitals on this serial (BRIEF §5.G.2, `prescriptions.vitals.record`). Unlike `view`, this
     * is scoped: the patient must be in the building (checked in or in the chamber — SerialStatus::isPresent, the
     * same rule that puts the button on the board row) and the serial must sit at a branch this user acts for
     * (BranchAccess). A receptionist on the main desk does not open encounters for a booked serial at another
     * branch, and a compounder does not open one on a doctor they do not work for (DoctorScope) — the vitals screen
     * is the widest write the role holds, so the boundary is enforced here rather than at the screen.
     */
    public function recordVitals(User $user, Serial $serial): bool
    {
        return $user->is_active
            && $user->can(Permission::PrescriptionsVitalsRecord->value)
            && $serial->status->isPresent()
            && $this->branches->actsFor($user, (int) $serial->sessionInstance->branch_id)
            && $this->scope->allows($user, $this->doctorOf($serial));
    }

    /**
     * Opening the clinical encounter behind a serial (POST /panel/serials/{serial}/visit — the doctor screen's
     * "Prescribe", and the same idempotent StartVisit the SerialCalled listener runs).
     *
     * That route used to authorise `view`, which only asks "may you READ this row". So an encounter could be opened
     * — a `visits` row written, the patient's visit_count bumped, a writer URL handed back — for a patient who had
     * not walked in yet, by anyone active enough to see the board. `recordVitals` already refuses that through
     * SerialStatus::isPresent ("the only states in which anything clinical can be done to them at the desk"), and
     * this is the same clinical move, so it carries the same presence rule plus a reason to be in the chart:
     * `prescriptions.write` (the doctor's own path from the chamber) or `prescriptions.vitals.record` (the desk that
     * takes the readings, which is what OpenVisitForVitals does through recordVitals).
     *
     * No BranchAccess conjunct here, deliberately, unlike recordVitals: a doctor drives their own chamber from
     * whatever branch they happen to be signed in at, and refusing them their own patient's sheet because they had
     * not switched the branch picker would break the one path this fix must not break.
     */
    public function startVisit(User $user, Serial $serial): bool
    {
        return $user->is_active
            && ($user->can(Permission::PrescriptionsWrite->value) || $user->can(Permission::PrescriptionsVitalsRecord->value))
            && $serial->status->isPresent()
            && $this->scope->allows($user, $this->doctorOf($serial));
    }

    /**
     * Arrived / no-show / reinstate — the arrival desk's three moves, and the whole of what a compounder may do to
     * a serial. It used to be a role list (receptionist | admin | doctor); it is now `serials.check-in`, held by
     * exactly those three roles plus the compounder, so adding a role to the desk is a matrix edit and not a policy
     * edit. The doctor scope on top is what makes the compounder's desk *their doctor's* desk.
     *
     * The role list is still OR-ed in as a TRANSITIONAL fallback, and this is why: `serials.check-in` only exists in
     * a tenant schema once RolesAndPermissionsSeeder has run there, and the deploy step that runs it
     * (`tenants:migrate --seed`) SKIPS suspended tenants — DEPLOYMENT.md §3: "a suspended tenant is skipped until
     * `tenants:migrate --tenant=<slug>` after reactivation". Spatie's Gate::before swallows PermissionDoesNotExist
     * and answers false, so on a tenant reactivated after this deploy but before its seeder run, `can()` would be a
     * silent no: no arrival tick on any board row, every check-in POST 403, nothing in the log saying why — and
     * postpone, which keeps its own role list, still working. "I can postpone this patient but I cannot mark them
     * arrived" is not a failure a front desk can diagnose.
     *
     * It is safe because it cannot widen the compounder: they hold none of receptionist / hospital_admin / doctor,
     * so the OR is false for them and the permission is still the only door they come through — and the DoctorScope
     * conjunct sits OUTSIDE the OR, so it applies whichever branch answered. The OR itself lives in checkInAny()
     * below, because the board has to read the same one; drop it there (with `heldCheckInBeforeThePermission` and
     * tests/Feature/Serials/CheckInPermissionFallbackTest) in the release AFTER the one that ships
     * `serials.check-in`, by which time every tenant — reactivated stragglers included — has been through the
     * seeder at least once.
     */
    public function checkIn(User $user, Serial $serial): bool
    {
        return $this->checkInAny($user) && $this->scope->allows($user, $this->doctorOf($serial));
    }

    /**
     * The same rule with no row in hand — "may this user mark arrivals AT ALL" — which is the board's `can.check_in`
     * flag (Reception\BoardController). The row-level answer is still checkIn() above.
     *
     * It is a class-level ability rather than a second `can('serials.check-in')` at the call site because the
     * transitional fallback would otherwise be server-side only: on an unseeded tenant the POST succeeded through the
     * OR while the board asked the bare permission, answered false, and SessionTile rendered no arrival tick on any
     * row. A desk that may check a patient in and is never offered the control is the dead front desk the fallback
     * exists to prevent, so both callers read one OR, in one place, deleted in one edit.
     *
     * No DoctorScope conjunct: a flag asked without a row cannot name a doctor, and every row a restricted user's
     * board carries is one of their own doctors' by construction (BoardBuilder is scoped) — the scope is applied per
     * row by checkIn(), which is what every POST goes through.
     */
    public function checkInAny(User $user): bool
    {
        return $user->is_active
            && ($user->can(Permission::SerialsCheckIn->value) || $this->heldCheckInBeforeThePermission($user));
    }

    /**
     * Call / skip / return (SERIAL_ENGINE §6 "Doctor, Reception"): `queue.call-next`, and — the doctor-channel rule of
     * ChannelGuards::doctor — a user who IS a doctor only drives their own session. The permission opens every
     * chamber to an operator (reception, admin), never a colleague's chamber to a doctor, and DoctorScope narrows
     * "every chamber" to "the chambers you were assigned" for anyone the compounder role restricts.
     */
    public function call(User $user, Serial $serial): bool
    {
        return $user->can(Permission::QueueCallNext->value)
            && SessionInstancePolicy::drivesSession($user, (int) $serial->sessionInstance->doctor_id)
            && $this->scope->allows($user, $this->doctorOf($serial));
    }

    public function complete(User $user, Serial $serial): bool
    {
        return ($user->hasRole(Role::Doctor->value) || $user->can(Permission::QueueCallNext->value))
            && $this->scope->allows($user, $this->doctorOf($serial));
    }

    public function noShow(User $user, Serial $serial): bool
    {
        return $this->checkIn($user, $serial);
    }

    public function reinstate(User $user, Serial $serial): bool
    {
        return $this->checkIn($user, $serial);
    }

    public function cancel(User $user, Serial $serial): bool
    {
        return ($user->can(Permission::SerialsCancel->value) || $user->hasRole(Role::HospitalAdmin->value))
            && $this->scope->allows($user, $this->doctorOf($serial));
    }

    /**
     * Written out rather than delegated to checkIn(), deliberately: postponing moves a patient to another day and
     * re-issues their number, which is a serial-number decision the compounder does not get ("he can't be able to
     * edit the serial number"). If this delegated, the day `checkIn` became a permission the compounder would have
     * silently gained it — and it must not pick the list up from `heldCheckInBeforeThePermission` either, so that
     * deleting that transitional helper cannot quietly change who may postpone.
     */
    public function postpone(User $user, Serial $serial): bool
    {
        return $user->is_active
            && ($user->hasRole(Role::Receptionist->value) || $user->hasRole(Role::HospitalAdmin->value) || $user->hasRole(Role::Doctor->value))
            && $this->scope->allows($user, $this->doctorOf($serial));
    }

    public function transfer(User $user, Serial $serial): bool
    {
        return ($user->can(Permission::SerialsTransfer->value) || $user->hasRole(Role::Doctor->value))
            && $this->scope->allows($user, $this->doctorOf($serial));
    }

    public function reorder(User $user, Serial $serial): bool
    {
        return $user->can(Permission::SerialsReorder->value) && $this->scope->allows($user, $this->doctorOf($serial));
    }

    public function priority(User $user, Serial $serial): bool
    {
        return $user->can(Permission::SerialsReorder->value) && $this->scope->allows($user, $this->doctorOf($serial));
    }

    /**
     * The three roles `checkIn` answered to before `serials.check-in` existed. TRANSITIONAL — the whole rationale,
     * and when to delete it, is in checkIn()'s docblock. Nothing else may call this: `postpone` spells its identical
     * list out itself so that removing this method is a change to check-in only.
     */
    private function heldCheckInBeforeThePermission(User $user): bool
    {
        return $user->hasRole(Role::Receptionist->value)
            || $user->hasRole(Role::HospitalAdmin->value)
            || $user->hasRole(Role::Doctor->value);
    }

    /** The serial's doctor is its session's — read from the relation the board already handed over, never fetched per row. */
    private function doctorOf(Serial $serial): int
    {
        return (int) $serial->sessionInstance->doctor_id;
    }
}
