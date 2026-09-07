<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialReordered;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Serials\Exceptions\NeedsRenormalisation;
use App\Domain\Serials\Exceptions\ReorderStale;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use Illuminate\Support\Facades\DB;

/**
 * Drag reorder (SERIAL_ENGINE §7.2): place $serial after $afterSerialId and/or before $beforeSerialId. Both neighbours
 * must belong to the same instance, be non-terminal and be adjacent in the current order (else 409 reorder_stale);
 * the moved serial must be booked|checked_in. Never changes number or display_code. Event `reordered` + audit
 * `reorder` + version bump; SerialReordered after commit.
 */
final class ReorderSerial
{
    public function __construct(
        private readonly PositionService $positions,
        private readonly SerialEventWriter $events,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Serial $serial, ?int $afterSerialId, ?int $beforeSerialId, Actor $actor, ?string $reason = null): Serial
    {
        if ($afterSerialId === null && $beforeSerialId === null) {
            throw new ReorderStale('A reorder needs at least one neighbour.');
        }

        return DB::transaction(function () use ($serial, $afterSerialId, $beforeSerialId, $actor, $reason): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [SerialStatus::Booked, SerialStatus::CheckedIn], true)) {
                throw new IllegalTransition($locked->status, $locked->status);
            }

            $s = SessionLocks::lockSession($locked->session_instance_id);

            $after = $afterSerialId === null ? null : $this->neighbour($afterSerialId, $locked);
            $before = $beforeSerialId === null ? null : $this->neighbour($beforeSerialId, $locked);

            // Fill the missing neighbour with the actual adjacent serial so a one-sided request stays exact.
            $live = DB::table('serials')->where('session_instance_id', $s->id)->whereIn('status', SerialStatus::nonTerminalValues())->where('id', '<>', $locked->id);

            if ($after !== null && $before === null) {
                $next = (clone $live)->where('position', '>', $after->position)->orderBy('position')->orderBy('number')->first(['id', 'position', 'display_code']);
                $before = $next === null ? null : Serial::query()->find((int) $next->id);
            } elseif ($before !== null && $after === null) {
                $prev = (clone $live)->where('position', '<', $before->position)->orderByDesc('position')->orderByDesc('number')->first(['id', 'position', 'display_code']);
                $after = $prev === null ? null : Serial::query()->find((int) $prev->id);
            }

            if ($after !== null && $before !== null) {
                if ($after->position >= $before->position) {
                    throw new ReorderStale;
                }

                $between = (clone $live)->where('position', '>', $after->position)->where('position', '<', $before->position)->exists();

                if ($between) {
                    throw new ReorderStale;
                }
            }

            $from = $locked->position;

            try {
                $to = $this->positions->between($after?->position, $before?->position);
            } catch (NeedsRenormalisation) {
                $this->positions->renormalise($s, $actor);
                $after = $after === null ? null : $after->fresh();
                $before = $before === null ? null : $before->fresh();
                $to = $this->positions->between($after?->position, $before?->position);
            }

            $locked->forceFill(['position' => $to])->save();

            $meta = ['after' => $after?->display_code, 'before' => $before?->display_code, 'reason' => $reason];
            $this->events->write($s, $locked, SerialEventType::Reordered, $meta, $actor, ['from_position' => $from, 'to_position' => $to, 'reason' => $reason]);
            $this->audit->record(AuditAction::Reorder, $locked, ['position' => $from], ['position' => $to], array_filter($meta + ['actor_user_id' => $actor->userId], fn ($v) => $v !== null));

            CountsRecalculator::bumpVersion($s->id);
            SerialReordered::dispatch($locked, $s, $from, $to);

            return $locked;
        });
    }

    private function neighbour(int $id, Serial $moved): Serial
    {
        $n = Serial::query()->find($id);

        if ($n === null || $n->session_instance_id !== $moved->session_instance_id || $n->status->isTerminal() || $n->id === $moved->id) {
            throw new ReorderStale;
        }

        return $n;
    }
}
