<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\CashShiftStatus;
use App\Domain\Billing\Exceptions\ShiftAlreadyOpen;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashShift;
use App\Models\Tenant\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** Open the drawer for a receptionist at a branch. `cash_shifts_user_id_uniq_p` allows exactly one. */
final class OpenCashShift
{
    public function handle(User $user, Branch $branch, int $openingFloatPaisa, Actor $actor): CashShift
    {
        try {
            return DB::transaction(function () use ($user, $branch, $openingFloatPaisa): CashShift {
                if (CashShift::query()->where('user_id', $user->id)->where('status', CashShiftStatus::Open->value)->exists()) {
                    throw new ShiftAlreadyOpen;
                }

                $shift = new CashShift;
                $shift->forceFill([
                    'user_id' => $user->id,
                    'branch_id' => $branch->id,
                    'status' => CashShiftStatus::Open,
                    'opened_at' => now(),
                    'opening_float_paisa' => max(0, $openingFloatPaisa),
                ])->save();

                return $shift;
            });
        } catch (QueryException $e) {
            // Two tabs raced; the partial unique index decided. Whoever lost sees the winner's message.
            if (CashShift::query()->where('user_id', $user->id)->where('status', CashShiftStatus::Open->value)->exists()) {
                throw new ShiftAlreadyOpen;
            }

            throw $e;
        }
    }
}
