<?php

declare(strict_types=1);

namespace App\Domain\Patients\Queries;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Services\AdvancePaymentPolicy;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\Support\QueueLinks;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Models\Tenant\Appointment;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * The portal's "Upcoming serials" card (BRIEF §5.E + §5.H): every booking of a household from today onwards that is
 * not over, in ONE statement — appointments ⋈ serials ⋈ session_instances ⋈ doctors ⋈ branches ⋈ patients — plus at
 * most one Redis GET per distinct running/paused session today for the "now serving · N ahead" hint. The queue page
 * owns the live position and the ETA (QueueStateRepository); this reads a snapshot that already exists and never
 * rebuilds or recomputes one, so a cold cache simply means no hint.
 *
 * Callers pass the patient ids they are entitled to — HomeController passes the household of the patient guard's own
 * mobile — and the query trusts them: the scoping is the caller's, and the portal tests assert it.
 */
final class UpcomingSerialsQuery
{
    public const LIMIT = 20;

    /** draft (no serial yet), completed, cancelled, postponed (the number moved to another day's booking). */
    private const OVER = ['draft', 'completed', 'cancelled', 'postponed'];

    public function __construct(private readonly QueueStateRepository $queue, private readonly AdvancePaymentPolicy $advance) {}

    /**
     * @param  array<int, int>  $patientIds
     * @return list<array<string, mixed>>
     */
    public function fetch(array $patientIds): array
    {
        if ($patientIds === []) {
            return [];
        }

        $today = Clock::today()->toDateString();

        $rows = Appointment::query()
            ->join('serials as s', 's.id', '=', 'appointments.serial_id')
            ->join('session_instances as si', 'si.id', '=', 'appointments.session_instance_id')
            ->join('doctors as d', 'd.id', '=', 'appointments.doctor_id')
            ->join('branches as b', 'b.id', '=', 'appointments.branch_id')
            ->join('patients as p', 'p.id', '=', 'appointments.patient_id')
            ->whereIn('appointments.patient_id', $patientIds)
            ->where('si.session_date', '>=', $today)
            ->whereNotIn('appointments.status', self::OVER)
            ->orderBy('si.session_date')->orderBy('si.planned_start_at')->orderBy('s.position')->orderBy('s.number')
            ->limit(self::LIMIT)
            ->toBase()
            ->get([
                'appointments.public_id as appointment_id', 'appointments.status', 'appointments.created_at', 'appointments.is_telemedicine',
                'p.public_id as patient_id', 'p.name as patient_name',
                'd.slug as doctor_slug', 'd.name as doctor_name', 'd.name_bn as doctor_name_bn', 'd.room_label as doctor_room',
                'b.name as branch_name',
                'si.public_id as session_id', 'si.session_code', 'si.session_date', 'si.status as session_status',
                'si.planned_start_at', 'si.planned_end_at', 'si.delay_minutes',
                's.public_id as serial_id', 's.display_code', 's.number', 's.status as serial_status',
            ]);

        $states = $this->liveStates($rows->all(), $today);
        $holdMinutes = null;
        $out = [];

        foreach ($rows as $row) {
            $isToday = (string) $row->session_date === $today;
            $held = $row->status === AppointmentStatus::Pending->value;
            $live = $states[(string) $row->session_id] ?? null;

            if ($held) {
                $holdMinutes ??= $this->advance->holdMinutes();
            }

            $out[] = [
                'appointment_id' => (string) $row->appointment_id,
                'status' => (string) $row->status,
                'is_telemedicine' => (bool) $row->is_telemedicine,
                'is_today' => $isToday,
                'patient' => ['public_id' => (string) $row->patient_id, 'name' => (string) $row->patient_name],
                'doctor' => ['slug' => (string) $row->doctor_slug, 'name' => (string) $row->doctor_name, 'name_bn' => $row->doctor_name_bn, 'room' => $row->doctor_room],
                'branch' => ['name' => (string) $row->branch_name],
                'session' => [
                    'public_id' => (string) $row->session_id, 'code' => (string) $row->session_code, 'date' => (string) $row->session_date,
                    'status' => (string) $row->session_status,
                    'planned_start_at' => self::iso($row->planned_start_at), 'planned_end_at' => self::iso($row->planned_end_at),
                    'delay_minutes' => (int) $row->delay_minutes,
                ],
                'serial' => ['public_id' => (string) $row->serial_id, 'display_code' => (string) $row->display_code, 'number' => (int) $row->number, 'status' => (string) $row->serial_status],
                'queue_url' => $isToday ? QueueLinks::forSerial((string) $row->doctor_slug, (string) $row->serial_id) : null,
                'hold_expires_at' => $held ? CarbonImmutable::parse((string) $row->created_at)->addMinutes((int) $holdMinutes)->toIso8601ZuluString() : null,
                'pay_url' => $held ? route('site.booking.confirmed', ['appointment' => (string) $row->appointment_id], absolute: false) : null,
                'live' => $live === null ? null : self::hint($live, (string) $row->serial_id),
            ];
        }

        return $out;
    }

    /**
     * The existing QueueState snapshot of each distinct running/paused session today — one Redis GET per session, no
     * rebuild on a miss (REALTIME.md §4.2: the queue page and the poll endpoint own that).
     *
     * @param  array<int, object>  $rows
     * @return array<string, array<string, mixed>>
     */
    private function liveStates(array $rows, string $today): array
    {
        $states = [];

        foreach ($rows as $row) {
            $sessionId = (string) $row->session_id;

            if ((string) $row->session_date !== $today || array_key_exists($sessionId, $states)) {
                continue;
            }

            if (! in_array((string) $row->session_status, [SessionStatus::Running->value, SessionStatus::Paused->value], true)) {
                continue;
            }

            $states[$sessionId] = $this->queue->stateByPublicId($sessionId);
        }

        return array_filter($states);
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array{now_serving: string|null, ahead: int|null}
     */
    private static function hint(array $state, string $serialPublicId): array
    {
        $ahead = null;

        foreach (is_array($state['serials'] ?? null) ? $state['serials'] : [] as $serial) {
            if (is_array($serial) && ($serial['id'] ?? null) === $serialPublicId) {
                $ahead = (int) ($serial['ahead'] ?? 0);
                break;
            }
        }

        $now = is_array($state['now_serving'] ?? null) ? $state['now_serving'] : null;

        return ['now_serving' => $now === null ? null : (string) ($now['c'] ?? ''), 'ahead' => $ahead];
    }

    private static function iso(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->toIso8601ZuluString();
    }
}
