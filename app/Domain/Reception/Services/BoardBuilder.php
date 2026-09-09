<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Prescription\Data\VitalsStatus;
use App\Domain\Prescription\Queries\VitalsStatusQuery;
use App\Domain\Scheduling\Services\SessionMaterialiser;
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
 */
final class BoardBuilder
{
    public function __construct(
        private readonly SessionMaterialiser $materialiser,
        private readonly CapacityService $capacity,
        private readonly SerialPresenter $presenter,
        private readonly VitalsStatusQuery $vitals,
    ) {}

    /** @return array<string, mixed> */
    public function build(Branch $branch, CarbonImmutable $date, bool $withSerials = true): array
    {
        $this->materialise($branch, $date);

        $sessions = SessionInstance::query()
            ->where('branch_id', $branch->id)
            ->whereDate('session_date', $date->toDateString())
            ->with(['doctor', 'nowServing'])
            ->when($withSerials, fn ($q) => $q->with(['serials' => fn ($s) => $s->orderBy('position')->orderBy('number')]))
            ->orderBy('planned_start_at')->orderBy('session_code')
            ->get();

        $remaining = $this->capacity->remainingFor($sessions->pluck('id')->all());
        $patients = collect();
        $appointments = collect();
        $vitals = [];

        if ($withSerials) {
            $serials = $sessions->flatMap(fn (SessionInstance $s) => $s->serials);
            $patients = Patient::query()->whereIn('id', $serials->pluck('patient_id')->filter()->unique()->all())->get()->keyBy('id');
            $appointments = Appointment::query()->whereIn('serial_id', $serials->pluck('id')->all())->get()->keyBy('serial_id');
            // "Who still needs the compounder?" for the whole board in ONE grouped query, through the Prescription
            // module's own read surface (BRIEF §5.G.2). Only the rows that can hold a reading are asked about.
            $vitals = $this->vitals->forSerials($serials->filter(fn (Serial $s) => SerialPresenter::canHaveVitals($s))->pluck('id')->all());
        }

        return [
            'date' => $date->toDateString(),
            'branch' => ['public_id' => $branch->public_id, 'name' => $branch->name, 'code' => $branch->code, 'slug' => $branch->slug],
            'sessions' => $sessions->map(fn (SessionInstance $s) => $this->session($s, $remaining[$s->id] ?? CapacityService::empty(), $withSerials, $patients, $appointments, $vitals))->values()->all(),
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
        ];
    }

    /**
     * @param  array{online: int, counter: int, buffer: int, counter_in_blocks: int, released: int}  $remaining
     * @param  Collection<int|string, Patient>  $patients
     * @param  Collection<int|string, Appointment>  $appointments
     * @param  array<int, VitalsStatus>  $vitals
     * @return array<string, mixed>
     */
    private function session(SessionInstance $s, array $remaining, bool $withSerials, $patients, $appointments, array $vitals): array
    {
        $doctor = $s->doctor;

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
                    $serial,
                    $patients->get($serial->patient_id),
                    $appointments->get($serial->id),
                    SerialPresenter::canHaveVitals($serial) ? ($vitals[$serial->id] ?? VitalsStatus::none()) : null,
                ))->values()->all()
                : [],
        ];
    }

    /** Every doctor with a template or an override at the branch gets the day materialised (idempotent, SERIAL_ENGINE §2). */
    private function materialise(Branch $branch, CarbonImmutable $date): void
    {
        $doctorIds = DoctorSchedule::query()->active()->where('branch_id', $branch->id)->distinct()->pluck('doctor_id')
            ->merge(ScheduleOverride::query()->where('branch_id', $branch->id)->whereDate('override_date', $date->toDateString())->distinct()->pluck('doctor_id'))
            ->unique();

        foreach ($doctorIds as $doctorId) {
            $this->materialiser->ensureDay($branch->id, (int) $doctorId, $date);
        }
    }
}
