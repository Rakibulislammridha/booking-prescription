<?php

declare(strict_types=1);

namespace App\Domain\Reception\Sync;

use App\Domain\Reception\Enums\OfflineEventType;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\OfflineEvent;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Database\Eloquent\Model;

/**
 * The authorisation boundary of the offline replay path — the one the HTTP path gets from `authorize()` and the
 * replay path had none of.
 *
 * WHY IT EXISTS. A replayed event is not a lesser kind of request: `check_in` runs CheckInSerial, `issue_serial`
 * writes a serial AND its appointment, `collect_cash` takes money. Online each of those goes through a policy whose
 * every ability now ends in DoctorScope; offline they went through nothing at all, and `ReplayContext::serialRef`
 * resolves a non-local ref as "any serial in the tenant" — so a device batch naming a colleague's serial walked
 * straight past every conjunct the policies had just grown. The hole is reachable inside the stated threat model:
 * an account holding compounder AND receptionist registers a device (reception.devices.register), is an accepted
 * X-Actor-User (AuthenticateReceptionDevice admits receptionists) and posts whatever it likes.
 *
 * WHY HERE, AND NOT IN THE HANDLERS. One place, asked once per event, for the same reason DoctorScope itself is one
 * class: six handlers is six chances to forget, and the seventh handler someone adds next year would be born
 * unguarded. SyncReplayer::process() is already the single funnel every event goes through — batch replay and
 * `/sync/resolve` alike — so the check sits there, in front of the handler's transaction, and a handler stays what
 * it is: the domain move, not the door.
 *
 * IT REJECTS, IT DOES NOT THROW. An out-of-scope event becomes `status: rejected` with
 * `server_result.rejection = actor_not_permitted` — a code OFFLINE §7.2 already lists — so the device stores it,
 * never retries it, and shows it on the conflicts list as work that could not be recorded; the rest of the batch
 * replays normally, because a desk with one bad event still has a queue of good ones to land. The `offline_events`
 * row is the durable record: it keeps `actor_user_id`, the session and the payload, so "who tried what" survives
 * without a second log.
 *
 * THE ABILITY IS THE ONLINE ONE. Each type asks the same Gate ability its online twin asks, on the row the event
 * names — not a bespoke DoctorScope call — so the day the matrix changes, the replay follows the panel by itself:
 *
 * | type              | subject                                      | ability                        |
 * |-------------------|----------------------------------------------|--------------------------------|
 * | `register_patient`| —                                            | none: a stub names no doctor   |
 * | `issue_serial`    | SessionInstance ← `payload.sessionId`        | `issue`                        |
 * | `check_in`        | Serial ← `payload.serialRef`                 | `checkIn`                      |
 * | `collect_cash`    | Appointment ← the serial's booking           | `collect`                      |
 * | `print_token`     | Serial ← `payload.serialRef`                 | `view`                         |
 * | `void_local`      | SessionInstance ← the event's session        | `issue`                        |
 * | resolution `move_to_session` | the TARGET SessionInstance         | `issue` (as well as the above) |
 *
 * `register_patient` is deliberately unscoped: a walk-in stub belongs to no doctor yet, so there is nothing to
 * narrow by, and every actor the device guard admits may register a patient online. What the compounder may then
 * SEE of that patient is PatientAccessResolver's question, not this one.
 *
 * A SUBJECT THAT DOES NOT RESOLVE IS PASSED THROUGH, never denied: an unknown serial, a `local:` ref whose
 * register_patient/issue_serial has not been accepted yet, a collect_cash whose serial carries no booking. Each of
 * those is already an outcome the handler produces itself — `serial_not_found`, `session_not_found`,
 * `payload_invalid`, or a `pending` dependency — and none of them writes anything, so guessing at a denial here
 * would only turn a retryable dependency into a dead event.
 */
final class ReplayScope
{
    public function __construct(private readonly Gate $gate) {}

    /**
     * The outcome to persist INSTEAD of running the handler, or null when the actor may proceed.
     */
    public function refuse(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ?ReplayOutcome
    {
        $gate = $this->gate->forUser($ctx->actor);

        foreach ($this->subjects($event, $ctx, $resolution) as [$ability, $subject]) {
            if (! $gate->allows($ability, $subject)) {
                return ReplayOutcome::rejected(
                    'actor_not_permitted',
                    __('reception.errors.actor_not_permitted'),
                    self::sessionOf($subject) ?? $event->session_instance_id,
                );
            }
        }

        return null;
    }

    /**
     * The (ability, row) pairs this event has to clear — every one of them, in order.
     *
     * @return list<array{0: string, 1: Model}>
     */
    private function subjects(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution): array
    {
        $subjects = [];

        switch ($event->type) {
            case OfflineEventType::IssueSerial:
                $session = self::session((string) ($event->payload['sessionId'] ?? ''));

                if ($session !== null) {
                    $subjects[] = ['issue', $session];
                }

                break;

            case OfflineEventType::CheckIn:
                $serial = self::serialOf($event, $ctx);

                if ($serial !== null) {
                    $subjects[] = ['checkIn', $serial];
                }

                break;

            case OfflineEventType::CollectCash:
                $appointmentId = self::serialOf($event, $ctx)?->appointment_id;
                $appointment = $appointmentId === null ? null : Appointment::query()->find($appointmentId);

                if ($appointment !== null) {
                    $subjects[] = ['collect', $appointment];
                }

                break;

            case OfflineEventType::PrintToken:
                $serial = self::serialOf($event, $ctx);

                if ($serial !== null) {
                    $subjects[] = ['view', $serial];
                }

                break;

            case OfflineEventType::VoidLocal:
                $session = $event->session_instance_id !== null
                    ? SessionInstance::query()->find($event->session_instance_id)
                    : self::session((string) ($event->payload['sessionId'] ?? ''));

                if ($session !== null) {
                    $subjects[] = ['issue', $session];
                }

                break;

            default:   // register_patient (and the reserved types validateBatch() never admits)
                break;
        }

        // §8.3 move_to_session allocates the number in ANOTHER session: the target is a second subject, and the
        // one the receptionist actually chose — authorising only the conflicted session would let a decision card
        // put a patient into a chamber the decider may not touch.
        $target = $resolution?->string('session');

        if ($target !== null) {
            $session = self::session($target);

            if ($session !== null) {
                $subjects[] = ['issue', $session];
            }
        }

        return $subjects;
    }

    private static function serialOf(OfflineEvent $event, ReplayContext $ctx): ?Serial
    {
        $ref = isset($event->payload['serialRef']) ? (string) $event->payload['serialRef'] : null;

        return $ctx->serialRef($ref);
    }

    private static function session(string $publicId): ?SessionInstance
    {
        return $publicId === '' ? null : SessionInstance::query()->where('public_id', $publicId)->first();
    }

    /** So the rejected row still names the session the desk was working in, and the conflict card can say where. */
    private static function sessionOf(Model $subject): ?int
    {
        return match (true) {
            $subject instanceof SessionInstance => $subject->id,
            $subject instanceof Serial, $subject instanceof Appointment => $subject->session_instance_id,
            default => null,
        };
    }
}
