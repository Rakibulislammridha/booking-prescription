<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Exceptions\CompounderFromAnotherTenant;
use App\Domain\Clinic\Exceptions\NotACompounder;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Put a compounder on a doctor's desk. The `doctor_compounder` row this writes is a permission grant — it is the
 * only thing that lets that account see the doctor's board, patients and fees (DoctorScope) — so both invariants are
 * asserted here rather than trusted from the screen: the account must hold the `compounder` role, and it must belong
 * to this clinic.
 *
 * The pivot has no model of its own, so this is a deliberate non-Eloquent write (CONVENTIONS §4) and therefore
 * records its own audit row against the doctor: who was put on whose desk, and by whom.
 *
 * Re-assigning someone already assigned is a no-op, not an error: the unique index makes it harmless and a
 * double-submitted form should not 409 at a hospital desk.
 */
final class AssignCompounder
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Doctor $doctor, User $compounder, Actor $actor): void
    {
        if ($compounder->tenant_id !== Tenancy::id()) {
            throw new CompounderFromAnotherTenant;
        }

        if (! $compounder->hasRole(Role::Compounder->value)) {
            throw new NotACompounder($compounder->name);
        }

        if ($doctor->compounders()->whereKey($compounder->getKey())->exists()) {
            return;
        }

        DB::transaction(function () use ($doctor, $compounder, $actor): void {
            $doctor->compounders()->attach($compounder->getKey(), ['assigned_by_user_id' => $actor->userId]);

            $this->audit->record(AuditAction::Create, $doctor, null, null, [
                'assignment' => 'compounder',
                'compounder_user_id' => (int) $compounder->getKey(),
                'compounder_public_id' => $compounder->public_id,
                'assigned_by_user_id' => $actor->userId,
            ]);
        });
    }
}
