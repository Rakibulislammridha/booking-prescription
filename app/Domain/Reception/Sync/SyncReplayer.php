<?php

declare(strict_types=1);

namespace App\Domain\Reception\Sync;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Enums\OfflineEventType;
use App\Domain\Reception\Exceptions\ConflictNotFound;
use App\Domain\Reception\Exceptions\ResolutionNotAllowed;
use App\Domain\Reception\Exceptions\SyncBatchInvalid;
use App\Domain\Reception\Handlers\CheckInHandler;
use App\Domain\Reception\Handlers\CollectCashHandler;
use App\Domain\Reception\Handlers\IssueSerialHandler;
use App\Domain\Reception\Handlers\PrintTokenHandler;
use App\Domain\Reception\Handlers\RegisterPatientHandler;
use App\Domain\Reception\Handlers\VoidLocalHandler;
use App\Domain\Shared\Actor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * OFFLINE §7.2 — the server pipeline. One batch per device at a time (tenant-scoped Redis lock), events in
 * sequence_no order, each exactly once through `offline_events (reception_device_id, client_event_id)` +
 * `INSERT … ON CONFLICT DO NOTHING`: a re-sent event whose row is already final returns the stored server_result
 * verbatim; a `pending` row (a previous attempt died mid-flight) is processed again through idempotent handlers.
 * `depends_on` ordering yields `pending` / `dependency_unresolved` until the dependency is accepted. Handlers run
 * inside their own transaction; domain failures become `rejected`, anything else leaves the row `pending` for a retry.
 */
final class SyncReplayer
{
    public const MAX_BATCH = 200;

    public const LOCK_SECONDS = 120;

    public const LOCK_WAIT_SECONDS = 10;

    /** @var array<string, class-string<ReplayHandler>> */
    private const HANDLERS = [
        'register_patient' => RegisterPatientHandler::class,
        'issue_serial' => IssueSerialHandler::class,
        'check_in' => CheckInHandler::class,
        'collect_cash' => CollectCashHandler::class,
        'print_token' => PrintTokenHandler::class,
        'void_local' => VoidLocalHandler::class,
    ];

    /** Resolutions that need a Hospital Admin behind the device (OFFLINE §8.3 record_in_closed, §8.5 cash discard). */
    private const ADMIN_ONLY = ['record_in_closed', 'collect_cash:discard'];

    public function __construct(private readonly Container $container) {}

    /**
     * @param  array<int, array<string, mixed>>  $events  wire events: client_event_id, sequence_no, type, client_occurred_at, depends_on?, session_id?, payload
     *
     * @throws SyncBatchInvalid
     */
    public function replay(ReceptionDevice $device, User $actor, array $events, ?string $appVersion = null): SyncResult
    {
        $this->validateBatch($events);

        return $this->locked($device, function () use ($device, $actor, $events, $appVersion): SyncResult {
            $ctx = $this->context($device, $actor);
            $results = [];
            $stale = false;

            foreach ($events as $raw) {
                $row = $this->upsert($device, $actor, $raw);

                if ($row->status->isFinal()) {
                    // exactly-once: the stored outcome, verbatim
                    $ctx->prime($row);
                    $results[] = $row->toResult();

                    continue;
                }

                $outcome = $this->process($row, $ctx);
                $results[] = $row->refresh()->toResult();
                $stale = $stale || ($outcome->isAccepted() && in_array($row->type, [OfflineEventType::IssueSerial, OfflineEventType::CheckIn, OfflineEventType::CollectCash], true));
            }

            $device->forceFill(array_filter(['last_sync_at' => now(), 'app_version' => $appVersion], fn ($v) => $v !== null))->save();

            return new SyncResult($results, $stale);
        });
    }

    /**
     * OFFLINE §7.4: re-run the conflicted event's handler with the receptionist's decision attached.
     *
     * @return array<string, mixed> the same shape as a sync result
     */
    public function resolve(ReceptionDevice $device, User $actor, string $clientEventId, Resolution $resolution): array
    {
        return $this->locked($device, function () use ($device, $actor, $clientEventId, $resolution): array {
            $row = OfflineEvent::query()->where('reception_device_id', $device->id)->where('client_event_id', $clientEventId)->first();

            if ($row === null || $row->status !== OfflineEventStatus::Conflict || $row->conflict_reason === null) {
                throw new ConflictNotFound;
            }

            if (! in_array($resolution->resolution, $row->conflict_reason->resolutions(), true)) {
                throw new ResolutionNotAllowed($row->conflict_reason, $resolution->resolution);
            }

            if ($this->requiresAdmin($row, $resolution->resolution) && ! $actor->hasRole(Role::HospitalAdmin->value)) {
                throw new ResolutionNotAllowed($row->conflict_reason, $resolution->resolution, requiresAdmin: true);
            }

            $ctx = $this->context($device, $actor);
            $this->process($row, $ctx, $resolution);

            return $row->refresh()->toResult();
        });
    }

    /** @param  array<int, array<string, mixed>>  $events */
    private function validateBatch(array $events): void
    {
        if (count($events) > self::MAX_BATCH) {
            throw new SyncBatchInvalid('batch_too_large');
        }

        $previous = null;

        foreach ($events as $event) {
            $sequence = (int) ($event['sequence_no'] ?? 0);

            if ($previous !== null && $sequence <= $previous) {
                throw new SyncBatchInvalid('unordered');
            }

            if (! isset(self::HANDLERS[(string) ($event['type'] ?? '')])) {
                throw new SyncBatchInvalid('unsupported_type');
            }

            $previous = $sequence;
        }
    }

    /**
     * One batch per device at a time; the key is tenant-scoped because device ids repeat across schemas (CONVENTIONS §15).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function locked(ReceptionDevice $device, callable $callback): mixed
    {
        return Cache::lock('sync:'.Tenancy::id().':'.$device->id, self::LOCK_SECONDS)->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    private function context(ReceptionDevice $device, User $actor): ReplayContext
    {
        $role = $actor->getRoleNames()->first();

        return new ReplayContext($device, $actor, new Actor(
            userId: $actor->id,
            role: $role === null ? null : (string) $role,
            deviceId: $device->id,
            source: 'offline_replay',
        ));
    }

    /**
     * Idempotency (OFFLINE §7.2 a): INSERT … ON CONFLICT (reception_device_id, client_event_id) DO NOTHING, then read
     * the row that exists — ours or the earlier attempt's. Raw builder on purpose: the log row is not a clinical record
     * and the statement shape is the spec.
     *
     * @param  array<string, mixed>  $raw
     */
    private function upsert(ReceptionDevice $device, User $actor, array $raw): OfflineEvent
    {
        $clientEventId = (string) $raw['client_event_id'];
        $sessionId = isset($raw['session_id']) && is_string($raw['session_id']) ? SessionInstance::query()->where('public_id', $raw['session_id'])->value('id') : null;
        $occurred = isset($raw['client_occurred_at']) ? CarbonImmutable::parse((string) $raw['client_occurred_at']) : CarbonImmutable::now();

        DB::table('offline_events')->insertOrIgnore([
            'reception_device_id' => $device->id,
            'client_event_id' => $clientEventId,
            'sequence_no' => (int) $raw['sequence_no'],
            'type' => (string) $raw['type'],
            'depends_on' => isset($raw['depends_on']) && is_string($raw['depends_on']) && $raw['depends_on'] !== '' ? $raw['depends_on'] : null,
            'actor_user_id' => $actor->id,
            'session_instance_id' => is_numeric($sessionId) ? (int) $sessionId : null,
            'serial_block_id' => null,
            'payload' => json_encode(is_array($raw['payload'] ?? null) ? $raw['payload'] : [], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => OfflineEventStatus::Pending->value,
            'attempts' => 0,
            'client_occurred_at' => $occurred->utc(),
            'received_at' => now(),
        ]);

        /** @var OfflineEvent $row */
        $row = OfflineEvent::query()->where('reception_device_id', $device->id)->where('client_event_id', $clientEventId)->firstOrFail();

        return $row;
    }

    private function process(OfflineEvent $row, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        if ($resolution === null && $row->depends_on !== null) {
            $dependency = OfflineEvent::query()->where('reception_device_id', $row->reception_device_id)->where('client_event_id', $row->depends_on)->first();

            if ($dependency === null || $dependency->status !== OfflineEventStatus::Accepted) {
                $outcome = ReplayOutcome::pending($row->depends_on);
                $this->persist($row, $outcome, $ctx, null);

                return $outcome;
            }

            $ctx->prime($dependency);
        }

        try {
            $outcome = DB::transaction(fn (): ReplayOutcome => $this->handler($row->type)->handle($row, $ctx, $resolution));
        } catch (DomainException $e) {
            $outcome = ReplayOutcome::rejected($e->code(), $e->getMessage());
        } catch (Throwable $e) {
            // Left `pending`: the next batch (or the client's retry) processes it again through the idempotent handlers.
            report($e);
            $outcome = new ReplayOutcome(OfflineEventStatus::Pending, null, ['error' => 'retry']);
        }

        $this->persist($row, $outcome, $ctx, $resolution);

        return $outcome;
    }

    private function persist(OfflineEvent $row, ReplayOutcome $outcome, ReplayContext $ctx, ?Resolution $resolution): void
    {
        $columns = [
            'status' => $outcome->status,
            'conflict_reason' => $outcome->status === OfflineEventStatus::Pending ? $outcome->conflictReason : ($outcome->status === OfflineEventStatus::Conflict ? $outcome->conflictReason : null),
            'server_result' => $outcome->serverResult,
            'processed_at' => now(),
            'attempts' => $row->attempts + 1,
            'session_instance_id' => $outcome->sessionInstanceId ?? $row->session_instance_id,
            'serial_block_id' => $outcome->serialBlockId ?? $row->serial_block_id,
        ];

        if ($resolution !== null) {
            $columns += ['resolution' => $resolution->resolution, 'resolution_params' => $resolution->params, 'resolved_by_user_id' => $ctx->actor->id];
        }

        $row->forceFill($columns)->save();
    }

    private function handler(OfflineEventType $type): ReplayHandler
    {
        $class = self::HANDLERS[$type->value] ?? throw new SyncBatchInvalid('unsupported_type');

        /** @var ReplayHandler $handler */
        $handler = $this->container->make($class);

        return $handler;
    }

    private function requiresAdmin(OfflineEvent $row, ConflictResolution $resolution): bool
    {
        return in_array($resolution->value, self::ADMIN_ONLY, true) || in_array($row->type->value.':'.$resolution->value, self::ADMIN_ONLY, true);
    }
}
