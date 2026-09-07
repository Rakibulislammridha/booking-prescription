<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

use App\Domain\Prescription\Safety\SafetyReport;
use App\Models\Tenant\Prescription;

/** Result of SaveDraft / ApplyTemplate: the canonical draft (server parse wins) + the current alert set (§4.13). */
final readonly class DraftResult
{
    /** @param  array<string, mixed>  $prescription  the PrescriptionDraft wire shape */
    public function __construct(public Prescription $model, public array $prescription, public SafetyReport $report) {}

    /** @return array<string, mixed> the 200 body of PATCH …/draft */
    public function toArray(): array
    {
        return ['prescription' => $this->prescription, 'alerts' => $this->report->alertsArray(), 'issue_blocked_by' => $this->report->issueBlockedBy];
    }
}
