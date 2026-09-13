<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Prescription\Data\IssuedPrescriptionRef;
use App\Domain\Prescription\Data\VitalsStatus;
use App\Domain\Prescription\Queries\IssuedPrescriptionQuery;
use App\Domain\Prescription\Queries\VitalsStatusQuery;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Serials\Actions\CallNext;
use App\Domain\Serials\Services\CapacityService;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Patient;
use App\Models\Tenant\ScheduleOverride;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Today's board (BRIEF §5.F): every session of the branch on a date with booked/arrived/done/remaining counts, the
 * serials (desk shape, SerialPresenter) and now-serving. The same document is the Inertia props, the JSON the desk
 * polls every 5 s in degraded mode, and the `sessions` slice of the PWA bootstrap (REALTIME §3.1 `board.updated`).
 *
 * The rows are listed by serial NUMBER — B-001, B-002, B-003 — which is the order the tokens were handed out and
 * the one the waiting room reads off the wall. The engine's queue `position` (check-in order, priority inserts,
 * reorders — SERIAL_ENGINE §7) is a different order and is what CallNext consumes, so the session also names the
 * row CallNext would take next (`next_serial`, CallNext::nextOf on the rows already loaded): the list stays legible
 * and the desk is still told, in so many words, who is being called. The device cache applies the same order and
 * the same rule (shared/offline/board.ts) so an offline desk reads the board the same way.
 *
 * `$doctorIds` is the caller's DoctorScope answer and the ONE place the compounder's boundary is applied to this
 * document: null = every doctor at the branch (everyone who is not a compounder), a list = only those doctors, and
 * an EMPTY list = no sessions at all (an unassigned compounder has no desk yet, not the whole clinic's). Filtering
 * the session query is enough — the serials, patients, appointments, vitals and prescription handles below are all
 * keyed off the sessions that survive it, so they narrow by construction, and the `orderBy('number')` the rows are
 * listed in is untouched by construction too.
 */
final class BoardBuilder
{
    public function __construct(
        private readonly SessionMaterialiser $materialiser,
        private readonly CapacityService $capacity,
        private readonly SerialPresenter $presenter,
        private readonly VitalsStatusQuery $vitals,
        private readonly IssuedPrescriptionQuery $prescriptions,
    ) {}

    /**
     * @param  list<int>|null  $doctorIds  DoctorScope: null = unrestricted, a list = only these doctors, [] = none
     * @return array<string, mixed>
     */
    public function build(Branch $branch, CarbonImmutable $date, bool $withSerials = true, ?array $doctorIds = null): array
    {
        $this->materialise($branch, $date, $doctorIds);

        $sessions = SessionInstance::query()
            ->where('branch_id', $branch->id)
            ->when($doctorIds !== null, fn ($q) => $q->whereIn('doctor_id', $doctorIds ?? []))
            ->whereDate('session_date', $date->toDateString())
            ->with(['doctor', 'nowServing'])
            ->when($withSerials, fn ($q) => $q->with(['serials' => fn ($s) => $s->orderBy('number')->orderBy('position')]))
            ->orderBy('planned_start_at')->orderBy('session_code')
            ->get();

        $remaining = $this->capacity->remainingFor($sessions->pluck('id')->all());
        $patients = collect();
        $appointments = collect();
        $vitals = [];
        $prescriptions = [];

        if ($withSerials) {
            $serials = $sessions->flatMap(fn (SessionInstance $s) => $s->serials);
            $patients = Patient::query()->whereIn('id', $serials->pluck('patient_id')->filter()->unique()->all())->get()->keyBy('id');
            $appointments = Appointment::query()->whereIn('serial_id', $serials->pluck('id')->all())->get()->keyBy('serial_id');
            // "Who still needs the compounder?" for the whole board in ONE grouped query, through the Prescription
            // module's own read surface (BRIEF §5.G.2). Only the rows that can hold a reading are asked about.
            $vitals = $this->vitals->forSerials($serials->filter(fn (Serial $s) => SerialPresenter::canHaveVitals($s))->pluck('id')->all());
            // "Which rows have a prescription to print?" (BRIEF §5.G.4) — the same boundary, ONE more query for the
            // whole board, and only the handle of the issued version comes back (IssuedPrescriptionRef).
            $prescriptions = $this->prescriptions->forSerials($serials->filter(fn (Serial $s) => SerialPresenter::canHavePrescription($s))->pluck('id')->all());
        }

        return [
            'date' => $date->toDateString(),
            'branch' => ['public_id' => $branch->public_id, 'name' => $branch->name, 'code' => $branch->code, 'slug' => $branch->slug],
            'sessions' => $sessions->map(fn (SessionInstance $s) => $this->session($s, $remaining[$s->id] ?? CapacityService::empty(), $withSerials, $patients, $appointments, $vitals, $prescriptions))->values()->all(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array{online: int, counter: int, buffer: int, counter_in_blocks: int, released: int}  $remaining
     * @param  Collection<int|string, Patient>  $patients
     * @param  Collection<int|string, Appointment>  $appointments
     * @param  array<int, VitalsStatus>  $vitals
     * @param  array<int, IssuedPrescriptionRef>  $prescriptions
     * @return array<string, mixed>
     */
    private function session(SessionInstance $s, array $remaining, bool $withSerials, $patients, $appointments, array $vitals, array $prescriptions): array
    {
        $doctor = $s->doctor;
        $next = $withSerials ? CallNext::nextOf($s->serials) : null;

        return [
            'public_id' => $s->public_id,
            'code' => $s->session_code,
            'date' => $s->session_date->toDateString(),
            'status' => $s->status->value,
            'mode' => $s->mode->value,
            'doctor' => ['public_id' => $doctor->public_id, 'slug' => $doctor->slug, 'name' => $doctor->name, 'name_bn' => $doctor->name_bn, 'room' => $doctor->room_label],
            'planned_start_at' => $s->planned_start_at->toIso8601ZuluString(),
            'planned_end_at' => $s->planned_end_at->toIso8601ZuluString(),
            'expected_start_at' => $s->expectedStartAt()->toIso8601ZuluString(),
            'delay_minutes' => $s->delay_minutes,
            'now_serving' => $s->nowServing === null ? null : ['public_id' => $s->nowServing->public_id, 'display_code' => $s->nowServing->display_code],
            'next_serial' => $next === null ? null : ['public_id' => $next->public_id, 'display_code' => $next->display_code],
            'counts' => [
                'booked' => $s->booked_count, 'checked_in' => $s->checked_in_count, 'in_consultation' => $s->in_consultation_count,
                'completed' => $s->completed_count, 'no_show' => $s->no_show_count, 'cancelled' => $s->cancelled_count, 'postponed' => $s->postponed_count,
            ],
            'remaining' => $remaining,
            'fee_new_paisa' => $s->fee_new_paisa,
            'fee_followup_paisa' => $s->fee_followup_paisa,
            'max_serials' => $s->max_serials,
            'version' => $s->version,
            'serials' => $withSerials
                ? $s->serials->map(fn (Serial $serial) => $this->presenter->present(
                    // The row's own session, handed over rather than fetched: SerialResource needs it for the
                    // `session` block and SerialPresenter would otherwise `loadMissing` it once PER ROW, which is
                    // the one thing a board of a few hundred serials must not do.
                    $serial->setRelation('sessionInstance', $s),
                    $patients->get($serial->patient_id),
                    $appointments->get($serial->id),
                    SerialPresenter::canHaveVitals($serial) ? ($vitals[$serial->id] ?? VitalsStatus::none()) : null,
                    SerialPresenter::canHavePrescription($serial) ? ($prescriptions[$serial->id] ?? IssuedPrescriptionRef::none()) : null,
                ))->values()->all()
                : [],
        ];
    }

    /**
     * Every doctor with a template or an override at the branch gets the day materialised (idempotent,
     * SERIAL_ENGINE §2) — but only within the caller's scope: a compounder opening the board must not spend their
     * page load materialising twelve colleagues' days, and the sessions they would create are ones this caller can
     * never see. Somebody unrestricted opens the same board and materialises the rest.
     *
     * @param  list<int>|null  $only
     */
    private function materialise(Branch $branch, CarbonImmutable $date, ?array $only = null): void
    {
        $doctorIds = DoctorSchedule::query()->active()->where('branch_id', $branch->id)
            ->when($only !== null, fn ($q) => $q->whereIn('doctor_id', $only ?? []))->distinct()->pluck('doctor_id')
            ->merge(ScheduleOverride::query()->where('branch_id', $branch->id)->whereDate('override_date', $date->toDateString())
                ->when($only !== null, fn ($q) => $q->whereIn('doctor_id', $only ?? []))->distinct()->pluck('doctor_id'))
            ->unique();

        foreach ($doctorIds as $doctorId) {
            $this->materialiser->ensureDay($branch->id, (int) $doctorId, $date);
        }
    }
}
