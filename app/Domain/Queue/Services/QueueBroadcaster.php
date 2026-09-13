<?php

declare(strict_types=1);

namespace App\Domain\Queue\Services;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Queue\Events\BoardUpdated;
use App\Domain\Queue\Events\CallNext;
use App\Domain\Queue\Events\DoctorArrived;
use App\Domain\Queue\Events\QueueStateUpdated;
use App\Domain\Queue\Events\SerialCalled;
use App\Domain\Queue\Events\SerialCalledPrivate;
use App\Domain\Queue\Events\SerialStatusChanged;
use App\Domain\Queue\Events\SessionCancelled;
use App\Domain\Queue\Events\SessionDelayed;
use App\Domain\Queue\TenantChannel;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use App\Support\Clock;
use Illuminate\Broadcasting\Channel;

/**
 * Builds the wire payloads of REALTIME.md §3.1 and dispatches the `App\Domain\Queue\Events\*` broadcast classes.
 * Every payload is a plain array (no Eloquent is serialised) and carries `version` = `session_instances.version`
 * so a client can discard a frame older than the state it already holds.
 *
 * The public queue channel never carries a patient identifier. Of the private channels only the doctor's own channel
 * and the branch display carry a patient card — a first name, an age and a sex, nothing else (BRIEF §8,
 * REALTIME.md §3.1/§12).
 *
 * The reception channel used to receive that card too, and must not: it is one branch-wide channel every active
 * staff user of the branch holds, so a compounder assigned to Dr A — whose board, lists and policies are scoped to
 * Dr A by DoctorScope — was handed a live patient-by-patient feed of every other chamber over the socket, with no
 * HTTP request and no policy anywhere in the path. A channel cannot be narrowed per subscriber (one payload, many
 * listeners), so what must not leak is not broadcast: the desk's copy of `serial.called` is the public payload, and
 * the desk reads names from its own board rows, which ARE doctor-scoped.
 */
final class QueueBroadcaster
{
    public const PAYLOAD_VERSION = 1;

    /** @return array<int, Channel> */
    public function publicChannels(SessionInstance $session): array
    {
        return [TenantChannel::queue($session)];
    }

    /**
     * The two channels a patient card may ride on: the doctor's own screen and the branch's waiting-room display.
     * The reception channel is deliberately NOT here — see the class docblock.
     *
     * @return array<int, Channel>
     */
    public function chamberChannels(SessionInstance $session): array
    {
        $session->loadMissing(['branch', 'doctor']);

        return [TenantChannel::doctor($session->doctor), TenantChannel::display($session->branch)];
    }

    /**
     * REALTIME.md §3.1 `serial.called` on the queue channel (public payload) and on the three private channels, with
     * the patient card on the doctor's and the display's copy only; then `call.next` on those same two.
     */
    public function serialCalled(SessionInstance $session, Serial $serial, ?int $previousSerialId): void
    {
        $session->loadMissing(['branch', 'doctor']);
        $previous = $previousSerialId === null ? null : Serial::query()->whereKey($previousSerialId)->value('display_code');
        $room = $session->doctor->room_label;

        $base = [
            'v' => self::PAYLOAD_VERSION,
            'session' => $session->public_id,
            'version' => $session->version,
            'serial' => ['id' => $serial->public_id, 'code' => $serial->display_code, 'n' => $serial->number, 'pos' => $serial->position],
            'now_serving' => $serial->display_code,
            'previous' => $previous === null ? null : (string) $previous,
            'called_at' => ($serial->called_at ?? now())->toIso8601ZuluString(),
            'room' => $room,
        ];

        $patient = $this->patientCard($serial);

        SerialCalled::dispatch($base, $this->publicChannels($session));
        // The desk gets the call itself — it re-fetches the board on it — but not the card (class docblock).
        SerialCalledPrivate::dispatch($base, [TenantChannel::reception($session->branch)]);
        SerialCalledPrivate::dispatch([...$base, 'patient' => $patient], $this->chamberChannels($session));

        CallNext::dispatch([
            'v' => self::PAYLOAD_VERSION,
            'session' => $session->public_id,
            'doctor' => $session->doctor->public_id,
            'serial' => ['id' => $serial->public_id, 'code' => $serial->display_code],
            'patient' => [...$patient, 'vitals_taken' => $this->vitalsTaken($serial)],
            'room' => $room,
            'speak' => CallAnnouncement::speak($session->session_code, $serial->number, $room),
            'version' => $session->version,
        ], $this->chamberChannels($session));
    }

    /** REALTIME.md §3.1 `serial.status_changed` on queue + reception. */
    public function serialStatusChanged(SessionInstance $session, Serial $serial, string $from, string $to): void
    {
        $session->loadMissing('branch');

        SerialStatusChanged::dispatch([
            'v' => self::PAYLOAD_VERSION,
            'session' => $session->public_id,
            'version' => $session->version,
            'serial' => ['id' => $serial->public_id, 'code' => $serial->display_code],
            'from' => $from,
            'to' => $to,
            'at' => now()->toIso8601ZuluString(),
            'counts' => self::counts($session),
        ], [TenantChannel::queue($session), TenantChannel::reception($session->branch)]);
    }

    /** REALTIME.md §3.1/§10 `session.delayed` on queue + reception + display. */
    public function sessionDelayed(SessionInstance $session, ?string $message): void
    {
        $session->loadMissing('branch');

        SessionDelayed::dispatch([
            'v' => self::PAYLOAD_VERSION,
            'session' => $session->public_id,
            'version' => $session->version,
            'delay_minutes' => $session->delay_minutes,
            'expected_start_at' => $session->expectedStartAt()->toIso8601ZuluString(),
            'message' => $message,
            'message_bn' => self::bangla($message, $session->delay_minutes),
        ], [TenantChannel::queue($session), TenantChannel::reception($session->branch), TenantChannel::display($session->branch)]);
    }

    /** REALTIME.md §3.1 `session.cancelled` on queue + reception + display, with the doctor's next open sessions. */
    public function sessionCancelled(SessionInstance $session, ?string $reason): void
    {
        $session->loadMissing(['branch', 'doctor']);

        SessionCancelled::dispatch([
            'v' => self::PAYLOAD_VERSION,
            'session' => $session->public_id,
            'version' => $session->version,
            'reason' => $reason,
            'message' => $reason ?? __('queue.session.cancelled'),
            'alternatives' => $this->alternatives($session),
        ], [TenantChannel::queue($session), TenantChannel::reception($session->branch), TenantChannel::display($session->branch)]);
    }

    /** REALTIME.md §3.1 `doctor.arrived` on queue + reception + display. */
    public function doctorArrived(SessionInstance $session): void
    {
        $session->loadMissing('branch');

        DoctorArrived::dispatch([
            'v' => self::PAYLOAD_VERSION,
            'session' => $session->public_id,
            'version' => $session->version,
            'actual_start_at' => $session->actual_start_at?->toIso8601ZuluString(),
        ], [TenantChannel::queue($session), TenantChannel::reception($session->branch), TenantChannel::display($session->branch)]);
    }

    /**
     * REALTIME.md §3.1 `queue.state` on queue + display, coalesced; and `board.updated` per branch.
     *
     * @param  array<string, mixed>  $state
     */
    public function queueState(SessionInstance $session, array $state): void
    {
        $session->loadMissing('branch');

        QueueStateUpdated::dispatch($session->public_id, $state, [TenantChannel::queue($session), TenantChannel::display($session->branch)]);
        BoardUpdated::dispatch($session->branch_id, $session->branch->public_id, [TenantChannel::reception($session->branch)]);
    }

    /** @return array<string, int> */
    public static function counts(SessionInstance $session): array
    {
        return [
            'booked' => $session->booked_count,
            'checked_in' => $session->checked_in_count,
            'in_consultation' => $session->in_consultation_count,
            'completed' => $session->completed_count,
            'no_show' => $session->no_show_count,
            'cancelled' => $session->cancelled_count,
            'postponed' => $session->postponed_count,
        ];
    }

    /**
     * First name, age and sex — the only patient data the doctor and display channels carry (REALTIME.md §3.1).
     *
     * @return array{first_name: string|null, age: int|null, sex: string|null}
     */
    private function patientCard(Serial $serial): array
    {
        if ($serial->patient_id === null) {
            return ['first_name' => null, 'age' => null, 'sex' => null];
        }

        $patient = Patient::query()->find($serial->patient_id);

        if ($patient === null) {
            return ['first_name' => null, 'age' => null, 'sex' => null];
        }

        $first = trim((string) preg_replace('/\s.*$/u', '', trim($patient->name)));

        return [
            'first_name' => $first === '' ? null : $first,
            'age' => $patient->age_years,
            'sex' => match ($patient->gender) {
                Gender::Male => 'm',
                Gender::Female => 'f',
                Gender::Other => 'o',
                default => null,
            },
        ];
    }

    private function vitalsTaken(Serial $serial): bool
    {
        $visitId = Visit::query()->where('serial_id', $serial->id)->value('id');

        return $visitId !== null && Vital::query()->where('visit_id', $visitId)->exists();
    }

    /**
     * The doctor's next open sessions (7 days) so the page can offer a rebooking.
     *
     * @return array<int, array<string, mixed>>
     */
    private function alternatives(SessionInstance $session): array
    {
        $from = Clock::today();

        return SessionInstance::query()
            ->where('doctor_id', $session->doctor_id)
            ->whereKeyNot($session->id)
            ->whereIn('status', [SessionStatus::Scheduled->value, SessionStatus::Running->value])
            ->whereBetween('session_date', [$from->toDateString(), $from->addDays(7)->toDateString()])
            ->orderBy('session_date')->orderBy('planned_start_at')->limit(3)
            ->get(['public_id', 'session_code', 'session_date'])
            ->map(fn (SessionInstance $s) => ['code' => $s->session_code, 'date' => $s->session_date->toDateString(), 'public_id' => $s->public_id])
            ->values()->all();
    }

    /** Free-text delay reasons are not translatable; a Bangla reason passes through, otherwise the generic line. */
    private static function bangla(?string $message, int $minutes): string
    {
        if ($message !== null && preg_match('/\p{Bengali}/u', $message) === 1) {
            return $message;
        }

        return trans('queue.delay.message_bn', ['minutes' => $minutes], 'bn');
    }
}
