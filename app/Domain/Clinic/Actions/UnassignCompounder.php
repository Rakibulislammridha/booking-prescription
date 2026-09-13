<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Exceptions\CompounderNotAssigned;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

/**
 * Take a compounder off a doctor's desk. Revocation is the half that has to be instant: DoctorScope re-reads the
 * pivot on every request and caches nothing, so the removed account loses that doctor's board, patients and fees on
 * its next click — no logout, no cache bust, no session rotation.
 *
 * Nothing they already recorded is touched; vitals and payments are clinical/financial history and keep their author.
 */
final class UnassignCompounder
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Doctor $doctor, User $compounder, Actor $actor): void
    {
        if (! $doctor->compounders()->whereKey($compounder->getKey())->exists()) {
            throw new CompounderNotAssigned;
        }

        DB::transaction(function () use ($doctor, $compounder, $actor): void {
            $doctor->compounders()->detach($compounder->getKey());

            $this->audit->record(AuditAction::Delete, $doctor, null, null, [
                'assignment' => 'compounder',
                'compounder_user_id' => (int) $compounder->getKey(),
                'compounder_public_id' => $compounder->public_id,
                'removed_by_user_id' => $actor->userId,
            ]);
        });
    }
}
