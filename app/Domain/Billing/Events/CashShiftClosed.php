<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Models\Tenant\CashShift;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The drawer was counted. A non-zero `variance_paisa` is what a supervisor notification would key on. */
final class CashShiftClosed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly CashShift $shift) {}
}
