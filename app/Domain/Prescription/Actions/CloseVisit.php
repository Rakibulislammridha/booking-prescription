<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Visit;

/** open → closed (idempotent). Drafts are left in place — a closed visit can still be amended later. */
final class CloseVisit
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Visit $visit, Actor $actor): Visit
    {
        if ($visit->status !== VisitStatus::Open) {
            return $visit;
        }

        $visit->forceFill(['status' => VisitStatus::Closed, 'ended_at' => now(), 'closed_by_user_id' => $actor->userId])->save();
        $this->auditor->visitClosed($visit);

        return $visit;
    }
}
