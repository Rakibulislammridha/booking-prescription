<?php

declare(strict_types=1);

namespace App\Domain\Reception\Handlers;

use App\Domain\Reception\Sync\ReplayContext;
use App\Domain\Reception\Sync\ReplayHandler;
use App\Domain\Reception\Sync\ReplayOutcome;
use App\Domain\Reception\Sync\Resolution;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\SessionInstance;

/** A voided unsynced slip (OFFLINE §6.1): session-level `void_local` serial_events row (serial_id NULL). Always accepted. */
final class VoidLocalHandler implements ReplayHandler
{
    public function __construct(private readonly SerialEventWriter $events) {}

    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        $p = $event->payload;
        $sessionId = $event->session_instance_id;

        if ($sessionId === null && isset($p['sessionId']) && is_string($p['sessionId'])) {
            $sessionId = SessionInstance::query()->where('public_id', $p['sessionId'])->value('id');
        }

        if ($sessionId === null) {
            return ReplayOutcome::accepted(['warning' => 'session_unresolved']);
        }

        $this->events->write((int) $sessionId, null, SerialEventType::VoidLocal, [
            'voided_client_event_id' => (string) ($p['voidedClientEventId'] ?? ''),
            'reason' => (string) ($p['reason'] ?? ''),
            'number' => $p['number'] ?? null,
        ], $ctx->actorDto, ['client_event_id' => $event->client_event_id]);

        return ReplayOutcome::accepted([], (int) $sessionId);
    }
}
