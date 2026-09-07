<?php

declare(strict_types=1);

namespace App\Domain\Billing\Queries;

use App\Models\Tenant\Invoice;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * What the clinic is still owed (BRIEF §5.I "partial payment and due tracking"). `due_paisa` is a GENERATED,
 * indexed column, so this is an index scan rather than a sum over payments.
 */
final class OutstandingDuesQuery
{
    /** @return Builder<Invoice> */
    public function builder(?int $branchId = null, ?int $patientId = null, ?int $doctorId = null): Builder
    {
        return Invoice::query()
            ->outstanding()
            ->when($branchId !== null, fn (Builder $q) => $q->where('branch_id', $branchId))
            ->when($patientId !== null, fn (Builder $q) => $q->where('patient_id', $patientId))
            ->when($doctorId !== null, fn (Builder $q) => $q->where('doctor_id', $doctorId))
            ->orderByDesc('issued_at');
    }

    /** @return LengthAwarePaginator<int, Invoice> */
    public function paginate(?int $branchId = null, int $perPage = 50): LengthAwarePaginator
    {
        return $this->builder($branchId)->with(['patient', 'doctor', 'branch'])->paginate($perPage);
    }

    /** The single number the desk and the patient record show. */
    public function totalPaisa(?int $branchId = null, ?int $patientId = null): int
    {
        return (int) $this->builder($branchId, $patientId)->sum('due_paisa');
    }

    /** @return array<int, array<string, mixed>> the patient's unpaid bills, newest first */
    public function forPatient(int $patientId, int $limit = 20): array
    {
        return $this->builder(null, $patientId)
            ->limit($limit)
            ->get(['id', 'public_id', 'number', 'status', 'total_paisa', 'paid_paisa', 'due_paisa', 'issued_at'])
            ->map(fn (Invoice $i): array => [
                'public_id' => $i->public_id,
                'number' => $i->number,
                'status' => $i->status->value,
                'total_paisa' => $i->total_paisa,
                'paid_paisa' => $i->paid_paisa,
                'due_paisa' => $i->due_paisa,
                'issued_at' => $i->issued_at?->toIso8601ZuluString(),
            ])
            ->all();
    }
}
