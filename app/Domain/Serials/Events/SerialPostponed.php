<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** old → new (next session). Consumers: Notifications (patient), Billing (no fee change), Queue (both sessions). */
final class SerialPostponed implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @var array<string, mixed> */
    public readonly array $old;

    /** @var array<string, mixed> */
    public readonly array $new;

    public function __construct(Serial $old, Serial $new)
    {
        $this->old = SerialEvent::snapshot($old);
        $this->new = SerialEvent::snapshot($new);
    }
}
