<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Exceptions\PrescriptionNotDraft;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Facades\DB;

/** Abandon a draft (incl. an amendment draft — the superseded row stays `issued`, PRESCRIPTION.md §6.3). */
final class DeleteDraft
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Prescription $draft, Actor $actor): void
    {
        DB::transaction(function () use ($draft): void {
            /** @var Prescription $rx */
            $rx = Prescription::query()->whereKey($draft->id)->lockForUpdate()->firstOrFail();

            if (! $rx->isDraft()) {
                throw new PrescriptionNotDraft($rx->id, $rx->status->value);
            }

            foreach (['items', 'investigations', 'advice', 'referrals'] as $relation) {
                foreach ($rx->{$relation}()->get() as $child) {
                    $child->delete();
                }
            }

            $visit = $rx->visit;

            if ($visit->current_prescription_id === $rx->id) {
                $visit->forceFill(['current_prescription_id' => $rx->supersedes_prescription_id])->save();
            }

            $this->auditor->draftDeleted($rx);
            $rx->delete();
        });
    }
}
