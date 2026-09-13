<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Serials\Concerns;

use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;

/**
 * A transfer has TWO sessions, and both of them used to be authorised as one.
 *
 * TransferSerialRequest asked `transfer` on the source serial, TransferSessionRequest `transferSession` on the
 * source session; the TARGET came out of a bare `Rule::exists` and was never asked about at all — and both
 * TransferSerial and TransferSession REQUIRE the target to belong to a different doctor. So a restricted account
 * holding `serials.transfer` could push its own doctor's patients into a chamber it may not even see, cancelling
 * its own serials on the way, and read the new rows back out of the response. One-sided authorisation on a
 * two-sided move.
 *
 * The target is authorised with **view**, deliberately, and not with `transferSession`. `view` already ends in the
 * DoctorScope conjunct, which is the whole of what is missing here, and it is the one ability a doctor holds on a
 * colleague's session: a doctor moving their own patient into another chamber is the normal case this must not
 * break, and `transferSession` (which needs `serials.transfer`, a permission no doctor holds) would start refusing
 * it. Restricted users are refused; everyone else is exactly where they were.
 *
 * It is asked inside authorize() so a refusal is a 403 about permission and not a 422 about a field. A
 * `target_session` that does not resolve is passed through on purpose: the `Rule::exists` on the field is what
 * answers that, and turning a typo into an authorisation failure tells the desk the wrong thing.
 */
trait AuthorisesTransferTarget
{
    protected function targetIsVisibleTo(User $user, mixed $publicId): bool
    {
        if (! is_string($publicId) || $publicId === '') {
            return true;
        }

        $target = SessionInstance::query()->where('public_id', $publicId)->first();

        return $target === null || $user->can('view', $target);
    }
}
