<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\CashShiftStatus;
use App\Domain\Billing\Events\CashShiftClosed;
use App\Domain\Billing\Exceptions\ShiftNotOpen;
use App\Domain\Billing\Services\ShiftReconciler;
use App\Domain\Shared\Actor;
use App\Models\Tenant\CashShift;
use Illuminate\Support\Facades\DB;

/**
 * Close the drawer: compute expected from the rows, store what was counted, and keep the variance exactly as it
 * falls out. A mismatch is recorded and audited, never silently corrected — that is the entire point of the
 * shift report (BRIEF §5.F).
 */
final class CloseCashShift
{
    public function __construct(
        private readonly ShiftReconciler $reconciler,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(CashShift $shift, int $countedCashPaisa, Actor $actor, ?string $note = null): CashShift
    {
        $closed = DB::transaction(function () use ($shift, $countedCashPaisa, $actor, $note): CashShift {
            /** @var CashShift $locked */
            $locked = CashShift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== CashShiftStatus::Open) {
                throw new ShiftNotOpen;
            }

            $totals = $this->reconciler->totals($locked);
            $counted = max(0, $countedCashPaisa);

            $locked->forceFill([
                'status' => CashShiftStatus::Closed,
                'closed_at' => now(),
                'expected_cash_paisa' => $totals['expected_cash_paisa'],
                'counted_cash_paisa' => $counted,
                'variance_paisa' => $counted - $totals['expected_cash_paisa'],
                'card_total_paisa' => $totals['card_total_paisa'],
                'mobile_money_total_paisa' => $totals['mobile_money_total_paisa'],
                'closing_note' => $note,
                'closed_by_user_id' => $actor->userId,
            ])->save();

            $this->audit->record(AuditAction::Update, $locked, null, [
                'expected_cash_paisa' => $locked->expected_cash_paisa,
                'counted_cash_paisa' => $locked->counted_cash_paisa,
                'variance_paisa' => $locked->variance_paisa,
            ], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'event' => 'shift_closed']);

            return $locked;
        });

        DB::afterCommit(fn () => CashShiftClosed::dispatch($closed));

        return $closed;
    }
}
