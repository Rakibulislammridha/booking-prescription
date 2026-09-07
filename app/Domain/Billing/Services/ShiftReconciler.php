<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Models\Tenant\CashShift;
use Illuminate\Support\Facades\DB;

/**
 * "Collected vs expected, per receptionist" (BRIEF §5.F). Expected cash is arithmetic on the rows, never a
 * running counter:
 *
 *   expected = opening float + cash payments in this shift − cash refunds paid out of this shift
 *
 * Card and mobile-money totals are informational: they never touched the drawer. `variance = counted − expected`
 * is stored as-is, sign and all — a shortfall is a fact to investigate, not something to round away.
 */
final class ShiftReconciler
{
    /** @return array{expected_cash_paisa: int, card_total_paisa: int, mobile_money_total_paisa: int, cash_in_paisa: int, cash_refunds_paisa: int, payment_count: int} */
    public function totals(CashShift $shift): array
    {
        $byMethod = [];
        $count = 0;

        // Raw aggregate on purpose: grouping Eloquent models by an enum-cast column is not a thing, and this is
        // a read-only sum inside the tenant schema.
        $rows = DB::table('payments')
            ->where('cash_shift_id', $shift->id)
            ->whereIn('status', [PaymentTxnStatus::Succeeded->value, PaymentTxnStatus::Refunded->value, PaymentTxnStatus::PartiallyRefunded->value])
            ->selectRaw('method, sum(amount_paisa) as total, count(*) as n')
            ->groupBy('method')
            ->get();

        foreach ($rows as $row) {
            $byMethod[(string) $row->method] = (int) $row->total;
            $count += (int) $row->n;
        }

        $sum = static fn (string ...$methods): int => array_sum(array_map(static fn (string $m): int => $byMethod[$m] ?? 0, $methods));

        $cashIn = $sum(PaymentMethod::Cash->value);
        $cashRefunds = (int) DB::table('refunds')
            ->where('cash_shift_id', $shift->id)
            ->where('status', RefundStatus::Processed->value)
            ->where('method', PaymentMethod::Cash->value)
            ->sum('amount_paisa');

        return [
            'expected_cash_paisa' => $shift->opening_float_paisa + $cashIn - $cashRefunds,
            'card_total_paisa' => $sum(PaymentMethod::Card->value),
            'mobile_money_total_paisa' => $sum(PaymentMethod::Bkash->value, PaymentMethod::Nagad->value, PaymentMethod::Sslcommerz->value),
            'cash_in_paisa' => $cashIn,
            'cash_refunds_paisa' => $cashRefunds,
            'payment_count' => $count,
        ];
    }
}
