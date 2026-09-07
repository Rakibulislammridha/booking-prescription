<?php

declare(strict_types=1);

namespace App\Domain\Reception\Handlers;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Reception\Sync\ReplayContext;
use App\Domain\Reception\Sync\ReplayHandler;
use App\Domain\Reception\Sync\ReplayOutcome;
use App\Domain\Reception\Sync\Resolution;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Models\Tenant\OfflineEvent;

/** Audit only (OFFLINE §6.1): a `printed` serial_events row with meta.format / meta.copies. Never conflicts. */
final class PrintTokenHandler implements ReplayHandler
{
    public function __construct(private readonly SerialEventWriter $events, private readonly AuditRecorder $audit) {}

    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        $p = $event->payload;
        $serial = $ctx->serialRef(isset($p['serialRef']) ? (string) $p['serialRef'] : null);

        if ($serial === null) {
            return ReplayOutcome::accepted(['warning' => 'serial_unresolved']);
        }

        $meta = ['format' => (string) ($p['format'] ?? '58'), 'copies' => max(1, (int) ($p['copies'] ?? 1)), 'offline' => true];
        $this->events->write($serial->session_instance_id, $serial, SerialEventType::Printed, $meta, $ctx->actorDto, ['client_event_id' => $event->client_event_id]);
        $this->audit->record(AuditAction::Print, $serial, null, $meta, ['actor_user_id' => $ctx->actor->id, 'actor_source' => 'offline_replay']);

        return ReplayOutcome::accepted([], $serial->session_instance_id);
    }
}
