<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialStatusChanged;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/**
 * The single choke point of the status state machine (SERIAL_ENGINE §6): locks the serial row FOR UPDATE, validates
 * the edge, stamps timestamps, writes serial_events + audit_logs, recalculates counts (which bumps the queue version)
 * and dispatches SerialStatusChanged after commit. Must be called inside a transaction.
 *
 * Context keys: reason (string), cancel_reason_code (CancelReason|string), reinstate_after_cancel (bool — the only
 * way to take the cancelled → checked_in edge), no_show_reason auto|manual|session_closed, passed (int), meta (array).
 */
final class SerialTransition
{
    /** @var array<string, array<int, string>> from => allowed to */
    public const EDGES = [
        'booked' => ['checked_in', 'in_consultation', 'no_show', 'cancelled', 'postponed'],
        'checked_in' => ['in_consultation', 'no_show', 'cancelled', 'postponed'],
        'in_consultation' => ['completed', 'checked_in'],
        'no_show' => ['checked_in', 'booked'],
        'cancelled' => ['checked_in'],   // only with context reinstate_after_cancel (OFFLINE.md §8.4)
        'completed' => [],
        'postponed' => [],
    ];

    public function __construct(
        private readonly SerialEventWriter $events,
        private readonly AuditRecorder $audit,
    ) {}

    public static function allows(SerialStatus $from, SerialStatus $to, bool $reinstateAfterCancel = false): bool
    {
        if ($from === SerialStatus::Cancelled) {
            return $reinstateAfterCancel && $to === SerialStatus::CheckedIn;
        }

        return in_array($to->value, self::EDGES[$from->value], true);
    }

    /**
     * @param  array<string, mixed>  $context
     *
     * @throws IllegalTransition
     */
    public function apply(Serial $serial, SerialStatus $to, Actor $actor, array $context = []): Serial
    {
        /** @var Serial $locked */
        $locked = Serial::query()->whereKey($serial->id)->lockForUpdate()->firstOrFail();
        $from = $locked->status;

        if (! self::allows($from, $to, (bool) ($context['reinstate_after_cancel'] ?? false))) {
            throw new IllegalTransition($from, $to);
        }

        $now = now();
        $stamps = ['status' => $to];

        switch ($to) {
            case SerialStatus::CheckedIn:
                if ($from === SerialStatus::NoShow || $from === SerialStatus::Cancelled) {
                    $stamps['reinstated_at'] = $now;
                    $stamps['passed_count'] = 0;
                }
                $stamps['checked_in_at'] = $from === SerialStatus::InConsultation ? $locked->checked_in_at ?? $now : $now;
                break;
            case SerialStatus::InConsultation:
                $stamps['called_at'] = $now;
                $stamps['checked_in_at'] = $locked->checked_in_at ?? $now;   // implicit check-in from booked
                break;
            case SerialStatus::Completed:
                $stamps['completed_at'] = $now;
                break;
            case SerialStatus::NoShow:
                $stamps['no_show_at'] = $now;
                break;
            case SerialStatus::Cancelled:
                $stamps['cancelled_at'] = $now;
                $reason = $context['cancel_reason_code'] ?? CancelReason::Other;
                $stamps['cancel_reason_code'] = $reason instanceof CancelReason ? $reason : CancelReason::from((string) $reason);
                break;
            case SerialStatus::Postponed:
                $stamps['postponed_at'] = $now;
                break;
            case SerialStatus::Booked:
                $stamps['reinstated_at'] = $now;
                $stamps['passed_count'] = 0;
                break;
        }

        $locked->forceFill($stamps)->save();

        $type = $this->eventType($from, $to, $context);
        $meta = (array) ($context['meta'] ?? []);

        if ($to === SerialStatus::NoShow) {
            $reason = (string) ($context['no_show_reason'] ?? 'manual');
            $meta += ['auto' => $reason === 'auto', 'reason' => $reason, 'passed' => (int) ($context['passed'] ?? $locked->passed_count)];
        }

        if ($to === SerialStatus::Cancelled) {
            $meta += ['cancel_reason_code' => $stamps['cancel_reason_code']->value];
        }

        $this->events->write($locked->session_instance_id, $locked, $type, $meta, $actor, [
            'from_status' => $from->value,
            'to_status' => $to->value,
            'reason' => isset($context['reason']) ? (string) $context['reason'] : null,
            'client_event_id' => isset($context['client_event_id']) ? (string) $context['client_event_id'] : null,
        ]);

        $this->audit->record($this->auditAction($to, $stamps), $locked, ['status' => $from->value], ['status' => $to->value], array_filter([
            'event' => $type->value,
            'reason' => $context['reason'] ?? null,
            'actor_user_id' => $actor->userId,
            'actor_source' => $actor->source,
        ], fn ($v) => $v !== null));

        CountsRecalculator::run($locked->session_instance_id);

        $session = $context['session'] ?? null;
        SerialStatusChanged::dispatch($locked, $session instanceof SessionInstance ? $session : null, $from->value, $to->value, $actor);

        return $locked;
    }

    /** @param  array<string, mixed>  $context */
    private function eventType(SerialStatus $from, SerialStatus $to, array $context): SerialEventType
    {
        return match (true) {
            $to === SerialStatus::CheckedIn && $from === SerialStatus::Cancelled => SerialEventType::ReinstatedAfterCancel,
            $to === SerialStatus::CheckedIn && $from === SerialStatus::NoShow => SerialEventType::Reinstated,
            $to === SerialStatus::Booked && $from === SerialStatus::NoShow => SerialEventType::Reinstated,
            $to === SerialStatus::CheckedIn && $from === SerialStatus::InConsultation => SerialEventType::Skipped,
            $to === SerialStatus::CheckedIn => SerialEventType::CheckedIn,
            $to === SerialStatus::InConsultation => SerialEventType::Called,
            $to === SerialStatus::Completed => SerialEventType::Completed,
            $to === SerialStatus::NoShow => SerialEventType::NoShow,
            $to === SerialStatus::Cancelled => SerialEventType::Cancelled,
            $to === SerialStatus::Postponed => SerialEventType::Postponed,
            default => SerialEventType::Booked,
        };
    }

    /** @param  array<string, mixed>  $stamps */
    private function auditAction(SerialStatus $to, array $stamps): AuditAction
    {
        return match (true) {
            $to === SerialStatus::CheckedIn => AuditAction::CheckIn,
            $to === SerialStatus::Cancelled && ($stamps['cancel_reason_code'] ?? null) === CancelReason::Transferred => AuditAction::Transfer,
            $to === SerialStatus::Cancelled => AuditAction::Void,
            default => AuditAction::Update,
        };
    }
}
