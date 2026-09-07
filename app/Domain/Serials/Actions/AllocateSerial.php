<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialAllocated;
use App\Domain\Serials\Exceptions\AllocationDriftDetected;
use App\Domain\Serials\Exceptions\AllocationRetryExhausted;
use App\Domain\Serials\Exceptions\BlockNotIssuable;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Serials\Exceptions\SessionNotAcceptingSerials;
use App\Domain\Serials\Exceptions\SlotUnavailable;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\DisplayCode;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;

/**
 * The atomic allocation (SERIAL_ENGINE §4). One transaction: lock the owner row (a released serial_blocks row first
 * for the counter pool, else the serial_pools row; the device's block for offline replay), read the session status,
 * take numbers until an insert succeeds (savepoint per attempt, cursor advanced even when the insert fails), write the
 * `booked` event, recalculate counts (bumps the queue version), dispatch SerialAllocated after commit.
 *
 * Every number is issued only while holding FOR UPDATE on its owner row (invariant I-OWNER), so concurrent
 * allocations in one pool are strictly serialised; `serials_session_number_uniq` is the backstop, not the mechanism.
 */
final class AllocateSerial
{
    public const MAX_ATTEMPTS = 5;

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly PositionService $positions,
        private readonly DisplayCode $codes,
        private readonly SerialEventWriter $events,
        private readonly AuditRecorder $audit,
        private readonly CapacityService $capacity,
        private readonly PriorityInsert $priorityInsert,
    ) {}

    /**
     * @throws PoolExhausted
     * @throws SessionNotAcceptingSerials
     * @throws SlotUnavailable
     * @throws AllocationRetryExhausted
     */
    public function __invoke(AllocationRequest $r): Serial
    {
        // 0. Idempotency (outside the lock; cheap): same client_event_id in the same session returns the existing row.
        if ($r->clientEventId !== null) {
            $existing = Serial::query()
                ->where('session_instance_id', $r->sessionInstanceId)
                ->where('client_event_id', $r->clientEventId)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        // 1. Whole allocation is one transaction; attempts: 3 retries only on deadlock (a retry re-runs the idempotency check).
        $serial = $this->db->connection('pgsql')->transaction(function () use ($r): Serial {
            if ($r->clientEventId !== null) {
                $existing = Serial::query()->where('session_instance_id', $r->sessionInstanceId)->where('client_event_id', $r->clientEventId)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // 2. Lock the owner row FIRST. This serialises every allocation in this pool.
            $owner = $this->lockOwnerRow($r);

            // 2b. Idempotency again, now that every earlier allocation of this pool has committed (a double-submit that
            //     raced the pre-lock check lands here and gets the existing row instead of burning a number).
            if ($r->clientEventId !== null) {
                $existing = Serial::query()->where('session_instance_id', $r->sessionInstanceId)->where('client_event_id', $r->clientEventId)->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            // 3. Session guard (plain SELECT: CloseSession/CancelSession lock all pools before they change status).
            /** @var SessionInstance $session */
            $session = SessionInstance::query()->findOrFail($r->sessionInstanceId);

            if (! $session->acceptsSerials()) {
                throw new SessionNotAcceptingSerials($session);
            }

            $actor = $this->actor($r);

            // 4. Take numbers until an insert succeeds (savepoint per attempt).
            for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
                if ($owner['next_number'] > $owner['range_end']) {
                    throw new PoolExhausted($r->pool, $r->sessionInstanceId, $this->capacity->remainingFor([$session->id])[$session->id] ?? []);
                }

                $number = $owner['next_number'];
                $this->advanceCursor($owner);                 // UPDATE owner SET next_number = next_number + 1 (or = number + 1 for a replayed block number)
                $owner['next_number'] = $number + 1;

                try {
                    // Nested transaction() == SAVEPOINT on Postgres; a unique violation aborts only the savepoint.
                    $serial = $this->db->connection('pgsql')->transaction(fn () => $this->insertSerial($r, $session, $number, $owner));
                } catch (UniqueConstraintViolationException $e) {
                    $constraint = self::violatedConstraint($e);

                    if (str_contains($constraint, 'slot_start_at')) {
                        throw new SlotUnavailable($session->id, $r->slotStartAt);   // the *slot* is taken, not the number: no retry
                    }

                    if (str_contains($constraint, 'client_event')) {
                        // Two owner rows (a released block and the pool, SKIP LOCKED) let a double-submit race past 2b;
                        // the index made us wait for the winner's commit, so its row is visible now.
                        /** @var Serial $existing */
                        $existing = Serial::query()->where('session_instance_id', $session->id)->where('client_event_id', $r->clientEventId)->firstOrFail();

                        return $existing;
                    }

                    $this->events->write($session, null, SerialEventType::NumberSkipped, ['number' => $number, 'reason' => 'unique_violation'], $actor);
                    report(new AllocationDriftDetected($session, $number, $e));  // must never happen: invariant I-OWNER breached

                    if ($r->isOffline()) {
                        throw new BlockNotIssuable('number_already_used', $owner['next_number']);
                    }

                    continue;
                }

                $this->events->write($session, $serial, SerialEventType::Booked, array_filter([
                    'pool' => $serial->pool->value,
                    'number' => $serial->number,
                    'display_code' => $serial->display_code,
                    'owner' => $owner['kind'],
                    'block_id' => $owner['kind'] === 'block' ? $owner['id'] : null,
                    'from_serial_id' => $r->transferredFromSerialId,
                ], fn ($v) => $v !== null), $actor, ['to_status' => SerialStatus::Booked->value, 'client_event_id' => $r->clientEventId]);

                $this->audit->record(AuditAction::Create, $serial, null, ['number' => $serial->number, 'display_code' => $serial->display_code, 'pool' => $serial->pool->value, 'source' => $serial->source->value], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source]);

                if ($r->priority !== SerialPriority::Normal) {
                    // In the same transaction, after the insert, so the trail shows both the allocation and the insert.
                    $serial = $this->priorityInsert->handle($serial, $r->priority, $actor, $r->priorityReason, $session);
                }

                CountsRecalculator::run($session->id);         // §6.4 — also bumps session_instances.version
                SerialAllocated::dispatch($serial, $session, $r->appointmentId);   // ShouldDispatchAfterCommit

                return $serial;
            }

            throw new AllocationRetryExhausted($r->sessionInstanceId);
        }, attempts: 3);

        $this->capacity->forget((string) SessionInstance::query()->whereKey($serial->session_instance_id)->value('public_id'));

        return $serial;
    }

    /**
     * §4.3. Counter pool: (a) a released serial_blocks row (SKIP LOCKED: two desks drain different rows in parallel),
     * (b) else the pool row. Online/buffer: (b) only. Offline replay: the device's active block.
     *
     * @return array{kind: 'pool'|'block', id: int, next_number: int, range_end: int}
     */
    private function lockOwnerRow(AllocationRequest $r): array
    {
        // Testing-only proof that the unique index holds the line without the lock (CONVENTIONS §6.5). No static switch.
        $lock = ! (app()->environment('testing') && (bool) config('serials.testing_skip_owner_lock', false));
        $forUpdate = $lock ? 'FOR UPDATE' : '';

        if ($r->isOffline()) {
            $row = $this->db->selectOne(
                "SELECT id, next_number, range_end, reception_device_id FROM serial_blocks WHERE id = :block AND status = 'active' {$forUpdate}",
                ['block' => $r->serialBlockId],
            );

            if ($row === null) {
                throw new BlockNotIssuable('block_not_active');
            }

            if ($r->receptionDeviceId !== null && (int) $row->reception_device_id !== $r->receptionDeviceId) {
                throw new BlockNotIssuable('block_not_owned');
            }

            $owner = ['kind' => 'block', 'id' => (int) $row->id, 'next_number' => (int) $row->next_number, 'range_end' => (int) $row->range_end];

            if ($r->number !== null) {
                if ($r->number < $owner['next_number'] || $r->number > $owner['range_end']) {
                    throw new BlockNotIssuable('number_out_of_block_range', $owner['next_number'] <= $owner['range_end'] ? $owner['next_number'] : null);
                }

                $owner['next_number'] = $r->number;   // issue *that* number; skipped ones stay unissued until release
            }

            return $owner;
        }

        if ($r->pool === SerialPool::Counter) {
            $skipLocked = $lock ? 'FOR UPDATE SKIP LOCKED' : '';
            $released = $this->db->selectOne(
                "SELECT id, next_number, range_end FROM serial_blocks WHERE session_instance_id = :sid AND status = 'released' AND next_number <= range_end ORDER BY range_start {$skipLocked} LIMIT 1",
                ['sid' => $r->sessionInstanceId],
            );

            if ($released !== null) {
                return ['kind' => 'block', 'id' => (int) $released->id, 'next_number' => (int) $released->next_number, 'range_end' => (int) $released->range_end];
            }
        }

        $pool = $this->db->selectOne(
            "SELECT id, next_number, range_end FROM serial_pools WHERE session_instance_id = :sid AND pool = :pool {$forUpdate}",
            ['sid' => $r->sessionInstanceId, 'pool' => $r->pool->value],
        );

        if ($pool === null) {
            throw new PoolExhausted($r->pool, $r->sessionInstanceId);
        }

        return ['kind' => 'pool', 'id' => (int) $pool->id, 'next_number' => (int) $pool->next_number, 'range_end' => (int) $pool->range_end];
    }

    /** @param  array{kind: 'pool'|'block', id: int, next_number: int, range_end: int}  $owner */
    private function advanceCursor(array $owner): void
    {
        $next = $owner['next_number'] + 1;

        if ($owner['kind'] === 'pool') {
            $this->db->update('UPDATE serial_pools SET next_number = :next, issued_count = issued_count + 1, lock_version = lock_version + 1, updated_at = now() WHERE id = :id', ['next' => $next, 'id' => $owner['id']]);

            return;
        }

        $this->db->update(
            "UPDATE serial_blocks SET next_number = :next, status = CASE WHEN :next2 > range_end THEN 'exhausted' ELSE status END, updated_at = now() WHERE id = :id",
            ['next' => $next, 'next2' => $next, 'id' => $owner['id']],
        );
    }

    /** @param  array{kind: 'pool'|'block', id: int, next_number: int, range_end: int}  $owner */
    private function insertSerial(AllocationRequest $r, SessionInstance $session, int $number, array $owner): Serial
    {
        $now = now();

        $id = $this->db->table('serials')->insertGetId([
            'public_id' => (string) Str::ulid(),
            'session_instance_id' => $session->id,
            'number' => $number,
            'display_code' => $this->codes::format($session->session_code, $number),
            'position' => $this->positions->initial($session, $number, $r->priority, $r->slotStartAt),
            'pool' => $r->pool->value,
            'status' => SerialStatus::Booked->value,
            'priority' => $r->priority->value,
            'source' => $r->source->value,
            'appointment_id' => $r->appointmentId,
            'patient_id' => $r->patientId,
            'serial_block_id' => $r->isOffline() ? $owner['id'] : null,
            'reception_device_id' => $r->receptionDeviceId,
            'client_event_id' => $r->clientEventId,
            'transferred_from_serial_id' => $r->transferredFromSerialId,
            'slot_start_at' => $r->slotStartAt?->utc(),
            'booked_at' => $now,
            'issued_by_user_id' => $r->actorUserId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var Serial $serial */
        $serial = Serial::query()->findOrFail($id);

        return $serial;
    }

    /** The constraint/index name from a Postgres 23505 message (the SQL text also names every column, so match the constraint only). */
    private static function violatedConstraint(UniqueConstraintViolationException $e): string
    {
        return preg_match('/unique constraint "([^"]+)"/', $e->getMessage(), $m) === 1 ? $m[1] : '';
    }

    private function actor(AllocationRequest $r): Actor
    {
        return new Actor(
            userId: $r->actorUserId,
            deviceId: $r->receptionDeviceId,
            patientId: $r->actorUserId === null && $r->receptionDeviceId === null ? $r->patientId : null,
            source: match (true) {
                $r->isOffline() => 'offline_replay',
                $r->actorUserId !== null => 'web',
                default => 'api',
            },
        );
    }
}
