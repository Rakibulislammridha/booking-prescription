<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Enums\OfflineEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Branch;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * Shift-close report (BRIEF §5.F "collected vs expected, per receptionist") from what exists today: serials issued
 * per user, statuses, expected fees (live appointments), collected (payment_status via the CashCollector / audit
 * receipts), and the device's offline cash events. Billing's cash_shifts replace the money columns later.
 *
 * `$doctorIds` (the caller's DoctorScope) narrows the whole report to those doctors' sessions: a compounder is
 * accountable for the fees they took at their doctor's desk, not for the branch's takings. Three of the four reads
 * inherit the filter from the session ids, but TWO reach the money by another road and are filtered explicitly —
 * the audit receipts (keyed by appointment, not by session) and the device's offline cash events (keyed by their
 * own session_instance_id). Miss either and the totals are still the whole branch's, which is the one number this
 * report must not show the wrong person.
 */
final class ShiftSummary
{
    /**
     * @param  list<int>|null  $doctorIds  DoctorScope: null = unrestricted, a list = only these doctors, [] = none
     * @return array<string, mixed>
     */
    public function build(Branch $branch, CarbonImmutable $date, ?array $doctorIds = null): array
    {
        $sessionIds = SessionInstance::query()->where('branch_id', $branch->id)
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('doctor_id', $doctorIds ?? []))
            ->whereDate('session_date', $date->toDateString())->pluck('id');
        $serials = Serial::query()->whereIn('session_instance_id', $sessionIds)->get();
        $appointments = Appointment::query()->whereIn('session_instance_id', $sessionIds)->get();
        $users = User::query()->whereIn('id', $serials->pluck('issued_by_user_id')->filter()->unique()->all())->get()->keyBy('id');

        $dayStart = $date->setTimezone(Clock::timezone())->startOfDay()->utc();
        $dayEnd = $dayStart->addDay();

        // The receipts are audit rows against an APPOINTMENT, so they carry neither branch nor session: for a
        // scoped caller they are narrowed to the appointments already loaded above, which are this scope's.
        $cashRows = AuditLog::query()
            ->where('auditable_type', (new Appointment)->getMorphClass())
            ->where('action', AuditAction::Update->value)
            ->whereNotNull('after->receipt_no')
            ->whereBetween('occurred_at', [$dayStart, $dayEnd])
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('auditable_id', $appointments->pluck('id')->all()))
            ->get(['actor_id', 'after', 'auditable_id']);

        $collectedByUser = [];
        $collectedTotal = 0;

        foreach ($cashRows as $row) {
            $after = (array) $row->after;
            $amount = (int) ($after['cash_collected_paisa'] ?? 0);
            $collectedTotal += $amount;
            $collectedByUser[(int) ($row->actor_id ?? 0)] = ($collectedByUser[(int) ($row->actor_id ?? 0)] ?? 0) + $amount;
        }

        $live = $appointments->filter(fn (Appointment $a) => $a->status->isLive() && $a->status !== AppointmentStatus::Draft);
        $offlineCash = OfflineEvent::query()
            ->where('type', OfflineEventType::CollectCash->value)
            ->where('status', OfflineEventStatus::Accepted->value)
            ->whereBetween('client_occurred_at', [$dayStart, $dayEnd])
            ->whereHas('device', fn ($q) => $q->where('branch_id', $branch->id))
            // An offline collection whose replay never resolved a session has no doctor to prove; for a scoped
            // caller the conservative answer is to leave it out rather than to count someone else's cash.
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('session_instance_id', $sessionIds->all()))
            ->get()
            ->sum(fn (OfflineEvent $e) => (int) ($e->payload['amount'] ?? 0));

        $perUser = $serials->groupBy(fn (Serial $s) => (int) ($s->issued_by_user_id ?? 0))->map(function ($rows, int $userId) use ($users, $collectedByUser) {
            return [
                'user' => $userId === 0 ? null : ['public_id' => $users->get($userId)?->public_id, 'name' => $users->get($userId)->name ?? __('reception.shift.self_service')],
                'issued' => $rows->count(),
                'by_source' => $rows->groupBy(fn (Serial $s) => $s->source->value)->map->count()->all(),
                'checked_in' => $rows->whereIn('status', [SerialStatus::CheckedIn->value, SerialStatus::InConsultation->value, SerialStatus::Completed->value])->count(),
                'cancelled' => $rows->where('status', SerialStatus::Cancelled)->count(),
                'collected_paisa' => $collectedByUser[$userId] ?? 0,
            ];
        })->values()->all();

        return [
            'date' => $date->toDateString(),
            'branch' => ['public_id' => $branch->public_id, 'name' => $branch->name],
            'sessions' => $sessionIds->count(),
            'serials' => [
                'issued' => $serials->count(),
                'by_status' => $serials->groupBy(fn (Serial $s) => $s->status->value)->map->count()->all(),
                'by_source' => $serials->groupBy(fn (Serial $s) => $s->source->value)->map->count()->all(),
            ],
            'money' => [
                'expected_paisa' => (int) $live->sum('fee_paisa'),
                'collected_paisa' => $collectedTotal,
                'offline_cash_paisa' => (int) $offlineCash,
                'paid_appointments' => $appointments->where('payment_status', PaymentStatus::Paid)->count(),
                'partial_appointments' => $appointments->where('payment_status', PaymentStatus::Partial)->count(),
                'unpaid_appointments' => $live->where('payment_status', PaymentStatus::Unpaid)->count(),
                'waived_paisa' => (int) $live->where('fee_paisa', 0)->sum('list_fee_paisa'),
            ],
            'per_user' => $perUser,
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }
}
