<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Domain\Clinic\Enums\Role;
use App\Http\Middleware\SetActiveBranch;
use App\Models\Tenant\User;

/**
 * Which branch a staff user may act for, in one place (the rule the reception channel guard has always used):
 * a hospital admin anywhere; anyone else at their default branch or at the branch they have switched to for this
 * session (SetActiveBranch → ActiveBranch, or the raw session key when the middleware did not run, as on the
 * broadcast-auth route). Policies that scope a row to "your branch" — the desk recording vitals on a serial,
 * for one — ask this rather than re-deriving it.
 */
final class BranchAccess
{
    public function __construct(private readonly ActiveBranch $active) {}

    public function actsFor(User $user, int $branchId): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->hasRole(Role::HospitalAdmin->value)) {
            return true;
        }

        if ($user->default_branch_id === $branchId) {
            return true;
        }

        return $this->activeBranchId() === $branchId;
    }

    private function activeBranchId(): ?int
    {
        if ($this->active->id() !== null) {
            return $this->active->id();
        }

        $fromSession = app()->bound('session') && session()->isStarted() ? session(SetActiveBranch::SESSION_KEY) : null;

        return is_numeric($fromSession) ? (int) $fromSession : null;
    }
}
