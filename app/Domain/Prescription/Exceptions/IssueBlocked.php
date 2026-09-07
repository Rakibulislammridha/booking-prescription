<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Exceptions;

use App\Domain\Prescription\Safety\SafetyReport;
use App\Domain\Shared\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** 422 `{issue_blocked_by: […], alerts: […]}` — a blocking safety alert at issue (PRESCRIPTION.md §6.1 step 4). */
final class IssueBlocked extends DomainException
{
    public function __construct(public readonly SafetyReport $report)
    {
        parent::__construct('Issue blocked by '.count($report->issueBlockedBy).' critical alert(s).');
    }

    public function code(): string
    {
        return 'prescriptions.issue_blocked';
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json(['message' => $this->getMessage(), 'code' => $this->code(), 'issue_blocked_by' => $this->report->issueBlockedBy, 'alerts' => $this->report->alertsArray()], 422);
    }
}
