<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/**
 * Bulk transfer (SERIAL_ENGINE §9.2): every non-terminal serial of the source by position, one committed transaction
 * each, stopping at the first PoolExhausted with a partial report; re-runs are idempotent by clientEventId.
 */
final class TransferSession
{
    public function __construct(private readonly TransferSerial $transfer) {}

    /** @return array{transferred: array<int, array{old: string, new: string}>, stopped_at: string|null, remaining: int} */
    public function handle(SessionInstance $source, SessionInstance $target, Actor $actor, ?string $reason = null): array
    {
        $serials = Serial::query()
            ->where('session_instance_id', $source->id)
            ->whereIn('status', SerialStatus::nonTerminalValues())
            ->orderBy('position')->orderBy('number')
            ->get();

        $done = [];
        $stoppedAt = null;
        $remaining = 0;

        foreach ($serials as $i => $serial) {
            try {
                $result = $this->transfer->handle($serial, $target, $actor, $reason);
                $done[] = ['old' => $result['old']->display_code, 'new' => $result['new']->display_code];
            } catch (PoolExhausted) {
                $stoppedAt = $serial->display_code;
                $remaining = $serials->count() - $i;
                break;
            }
        }

        return ['transferred' => $done, 'stopped_at' => $stoppedAt, 'remaining' => $remaining];
    }
}
