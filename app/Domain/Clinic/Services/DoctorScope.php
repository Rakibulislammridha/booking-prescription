<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\User;

/**
 * Which doctors may this staff user see and act for?
 *
 * One question, one answer, one place. A compounder is hired by a named doctor ("he can't be able to see other
 * doctors' patient lists"), so every board, list, policy and print path narrows through `doctorIds()` /`allows()`
 * instead of re-deriving the rule — and a surface that forgets to ask is the only way the boundary can leak.
 *
 * `null` means UNRESTRICTED and is the answer for everyone who is not a compounder: their existing rules (a
 * doctor's own patients, a receptionist's branch, an accountant's ledger) are untouched by this class and must keep
 * being applied by their own owners. A `list<int>` means "only these doctor ids", and an EMPTY list means nothing
 * at all — a compounder with no assignment is not a free-roaming desk user, they simply have no desk yet. Callers
 * must therefore distinguish `null` from `[]`; `$ids === []` is a deny, never a no-op.
 *
 * A hospital admin is unrestricted even if someone assigns them, so that the one account that must be able to
 * repair a clinic never locks itself out of it.
 *
 * NOTHING IS CACHED, here or anywhere downstream. Revoking an assignment has to take effect on the very next
 * request, and this codebase has already paid for state that was checked once and never re-checked (the
 * remember-me and idle-timeout fixes). Even a per-instance memo is unsafe: this object is autowired into
 * container-resolved collaborators (PatientAccessResolver, policies) whose lifetime under Octane is the worker's,
 * not the request's — `config/octane.php` flushes the tenancy singletons and nothing else, and ResetTenancy
 * resets the database session, not the container. One small indexed read per call is the price of being correct.
 */
final class DoctorScope
{
    /**
     * @return list<int>|null null = unrestricted; a list = only these doctor ids (empty list = nothing)
     */
    public function doctorIds(User $user): ?array
    {
        if ($user->hasRole(Role::HospitalAdmin->value) || ! $user->hasRole(Role::Compounder->value)) {
            return null;
        }

        /** @var list<int> $ids */
        $ids = $user->assignedDoctors()->pluck('doctors.id')->map(fn (mixed $id): int => (int) $id)->values()->all();

        return $ids;
    }

    /**
     * May this user act on a row that belongs to `$doctorId`? A restricted user is refused a null doctor id: a row
     * that names no doctor (an invoice raised at the counter, say) cannot be proven to be theirs.
     */
    public function allows(User $user, ?int $doctorId): bool
    {
        $ids = $this->doctorIds($user);

        if ($ids === null) {
            return true;
        }

        return $doctorId !== null && in_array($doctorId, $ids, true);
    }
}
