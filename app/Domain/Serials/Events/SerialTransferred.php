<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** old (cancelled, reason transferred) → new (other doctor). Billing reacts to the fee delta (SERIAL_ENGINE §9.2). */
final class SerialTransferred implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @var array<string, mixed> */
    public readonly array $old;

    /** @var array<string, mixed> */
    public readonly array $new;

    public function __construct(Serial $old, Serial $new, public readonly int $oldFeeSnapshot, public readonly int $targetFee)
    {
        $this->old = SerialEvent::snapshot($old);
        $this->new = SerialEvent::snapshot($new);
    }

    public function feeDeltaExpected(): int
    {
        return $this->targetFee - $this->oldFeeSnapshot;
    }
}
