<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialPriorityInserted;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Serials\Exceptions\ReasonRequired;
use App\Domain\Serials\Exceptions\VipDisabled;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\PositionService;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Sets serials.priority and the position by the rule table of SERIAL_ENGINE §7.3: emergency right after now_serving,
 * vip after the waiting emergencies, elderly after the next `serial.elderly_skip` waiting serials, normal back to
 * number × GAP (or the tail). Event `priority_changed` + audit `reorder`; SerialPriorityInserted after commit.
 */
final class PriorityInsert
{
    public function __construct(
        private readonly PositionService $positions,
        private readonly SerialEventWriter $events,
        private readonly AuditRecorder $audit,
        private readonly Settings $settings,
    ) {}

    public function handle(Serial $serial, SerialPriority $priority, Actor $actor, ?string $reason = null, ?SessionInstance $session = null): Serial
    {
        if ($priority === SerialPriority::Vip) {
            if (! (bool) $this->settings->get('serial.vip_enabled')) {
                throw new VipDisabled;
            }

            if ($reason === null || trim($reason) === '') {
                throw new ReasonRequired('insert a VIP serial');
            }
        }

        return DB::transaction(function () use ($serial, $priority, $actor, $reason, $session): Serial {
            /** @var Serial $locked */
            $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, [SerialStatus::Booked, SerialStatus::CheckedIn], true)) {
                throw new IllegalTransition($locked->status, $locked->status);
            }

            $s = $session !== null && $session->id === $locked->session_instance_id ? $session : SessionLocks::lockSession($locked->session_instance_id);
            PositionService::lockSession($s->id);

            $from = $locked->position;
            $fromPriority = $locked->priority;
            $locked->priority = $priority;   // natural() must see the new priority

            $to = match ($priority) {
                SerialPriority::Emergency => $this->positions->afterNowServing($s, 0, $locked->id, $actor),
                SerialPriority::Vip => $this->positions->afterNowServing($s, $this->positions->leadingEmergencies($s, $locked->id), $locked->id, $actor),
                SerialPriority::Elderly => $this->positions->afterNowServing($s, max(0, (int) $this->settings->get('serial.elderly_skip')), $locked->id, $actor),
                SerialPriority::Normal => $this->positions->natural($s, $locked),
            };

            $locked->forceFill(['priority' => $priority, 'position' => $to])->save();

            $this->events->write($s, $locked, SerialEventType::PriorityChanged, ['priority' => $priority->value, 'from_position' => $from, 'to_position' => $to, 'reason' => $reason], $actor, [
                'from_position' => $from, 'to_position' => $to, 'from_priority' => $fromPriority->value, 'to_priority' => $priority->value, 'reason' => $reason,
            ]);
            $this->audit->record(AuditAction::Reorder, $locked, ['priority' => $fromPriority->value, 'position' => $from], ['priority' => $priority->value, 'position' => $to], array_filter(['reason' => $reason, 'actor_user_id' => $actor->userId], fn ($v) => $v !== null));

            CountsRecalculator::bumpVersion($s->id);
            SerialPriorityInserted::dispatch($locked, $s, $from, $to, $priority->value);

            return $locked;
        });
    }
}
