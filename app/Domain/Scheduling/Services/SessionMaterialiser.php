<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Scheduling\Data\PlannedSession;
use App\Domain\Scheduling\Data\ResyncResult;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Serials\Services\PoolLayout;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\ScheduleOverride;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Session instance materialisation (SERIAL_ENGINE §2). Creation is one transaction: INSERT … ON CONFLICT DO NOTHING
 * RETURNING id (insertOrIgnore → affected-row count); rowcount 1 = we own creation and insert the three pools in the
 * same transaction; rowcount 0 = another transaction created it (Postgres made us wait for its commit), so SELECT it.
 * Never firstOrCreate (SELECT-then-INSERT races under Octane workers).
 */
final class SessionMaterialiser
{
    public function __construct(
        private readonly SessionPlanner $planner,
        private readonly CancelSession $cancelSession,
    ) {}

    /**
     * Ensure all instances for one doctor+branch on one date exist. Returns them (existing + created).
     *
     * @return Collection<int, SessionInstance>
     */
    public function ensureDay(int $branchId, int $doctorId, CarbonImmutable $date): Collection
    {
        $date = $date->setTimezone(Clock::timezone())->startOfDay();

        foreach ($this->planner->planFor($branchId, $doctorId, $date) as $planned) {
            $this->create($branchId, $doctorId, $date, $planned);
        }

        return SessionInstance::query()->forDay($branchId, $doctorId, $date)->orderBy('session_code')->get();
    }

    /** Ensure one specific instance exists (or return null if the schedule yields none). */
    public function ensure(int $branchId, int $doctorId, CarbonImmutable $date, string $sessionCode): ?SessionInstance
    {
        $date = $date->setTimezone(Clock::timezone())->startOfDay();
        $existing = SessionInstance::query()->forDay($branchId, $doctorId, $date)->where('session_code', $sessionCode)->first();

        if ($existing !== null) {
            return $existing;
        }

        $planned = $this->planner->planFor($branchId, $doctorId, $date)[$sessionCode] ?? null;

        return $planned === null ? null : $this->create($branchId, $doctorId, $date, $planned);
    }

    /** Scheduled sweep for every active doctor/branch pair with a template in the tenant. Returns the number of instances created. */
    public function materialiseRange(CarbonImmutable $from, CarbonImmutable $to): int
    {
        $from = $from->setTimezone(Clock::timezone())->startOfDay();
        $to = $to->setTimezone(Clock::timezone())->startOfDay();
        $created = 0;

        $pairs = DoctorSchedule::query()->active()->select(['doctor_id', 'branch_id'])->distinct()->get()
            ->map(fn (DoctorSchedule $s) => [$s->doctor_id, $s->branch_id])
            ->merge(ScheduleOverride::query()->whereBetween('override_date', [$from->toDateString(), $to->toDateString()])->select(['doctor_id', 'branch_id'])->distinct()->get()->map(fn (ScheduleOverride $o) => [$o->doctor_id, $o->branch_id]))
            ->unique(fn (array $p) => $p[0].':'.$p[1]);

        $activeDoctors = Doctor::query()->active()->pluck('id')->flip();
        $activeBranches = Branch::query()->active()->pluck('id')->flip();

        foreach ($pairs as [$doctorId, $branchId]) {
            if (! isset($activeDoctors[$doctorId]) || ! isset($activeBranches[$branchId])) {
                continue;
            }

            for ($date = $from; $date->lte($to); $date = $date->addDay()) {
                foreach ($this->planner->planFor($branchId, $doctorId, $date) as $planned) {
                    $wasCreated = false;
                    $this->create($branchId, $doctorId, $date, $planned, $wasCreated);
                    $created += $wasCreated ? 1 : 0;
                }
            }
        }

        return $created;
    }

    /**
     * Re-apply template/override changes to an existing instance if it is still untouched (every count 0, no blocks):
     * planned times, max_serials/quotas, mode and the pool ranges. A plan that no longer yields the session cancels it.
     */
    public function resync(SessionInstance $instance, ?Actor $actor = null): ResyncResult
    {
        $actor ??= Actor::system();
        $planned = $this->planner->planFor($instance->branch_id, $instance->doctor_id, $instance->session_date)[$instance->session_code] ?? null;

        if ($planned === null) {
            if ($instance->status === SessionStatus::Cancelled || $instance->status === SessionStatus::Closed) {
                return ResyncResult::NotFound;
            }

            $this->cancelSession->handle($instance, $actor, 'schedule_changed');

            return ResyncResult::Cancelled;
        }

        return DB::transaction(function () use ($instance, $planned): ResyncResult {
            $pools = SessionLocks::lockPools($instance->id);
            $session = SessionLocks::lockSession($instance->id);

            if (! $session->acceptsSerials()) {
                return ResyncResult::NotFound;
            }

            $touched = $session->booked_count + $session->checked_in_count + $session->in_consultation_count + $session->completed_count + $session->no_show_count + $session->cancelled_count + $session->postponed_count;

            if ($touched > 0 || DB::table('serials')->where('session_instance_id', $session->id)->exists()) {
                return ResyncResult::RefusedHasSerials;
            }

            if (SerialBlock::query()->where('session_instance_id', $session->id)->exists()) {
                return ResyncResult::RefusedHasBlocks;
            }

            foreach ($pools as $pool) {
                DB::table('serial_pools')->where('id', $pool->id)->update(['range_start' => 1, 'range_end' => 0, 'next_number' => 1]);
            }

            foreach (PoolLayout::ranges($planned->counterQuota, $planned->onlineQuota, $planned->bufferQuota) as $name => $range) {
                DB::table('serial_pools')->where('id', $pools[$name]->id)->update($range + ['updated_at' => now()]);
            }

            $session->forceFill([
                'planned_start_at' => $planned->plannedStartAt,
                'planned_end_at' => $planned->plannedEndAt,
                'delay_minutes' => $planned->delayMinutes,
                'mode' => $planned->mode,
                'slot_minutes' => $planned->slotMinutes,
                'max_serials' => $planned->maxSerials(),
                'counter_quota' => $planned->counterQuota,
                'online_quota' => $planned->onlineQuota,
                'buffer_quota' => $planned->bufferQuota,
                'auto_noshow_after' => $planned->autoNoshowAfter,
                'doctor_schedule_id' => $planned->doctorScheduleId,
                'version' => $session->version + 1,
            ])->save();

            $this->stampApplied($planned->overrideIds);

            return ResyncResult::Applied;
        });
    }

    /** The critical statement (§2.3). Returns the instance; $wasCreated tells whether this call inserted it. */
    private function create(int $branchId, int $doctorId, CarbonImmutable $date, PlannedSession $planned, bool &$wasCreated = false): SessionInstance
    {
        $wasCreated = false;

        return DB::transaction(function () use ($branchId, $doctorId, $date, $planned, &$wasCreated): SessionInstance {
            $now = now();
            $publicId = (string) Str::ulid();

            $affected = DB::table('session_instances')->insertOrIgnore([
                'public_id' => $publicId,
                'branch_id' => $branchId,
                'doctor_id' => $doctorId,
                'doctor_schedule_id' => $planned->doctorScheduleId,
                'session_date' => $date->toDateString(),
                'session_code' => $planned->sessionCode,
                'status' => SessionStatus::Scheduled->value,
                'mode' => $planned->mode->value,
                'slot_minutes' => $planned->slotMinutes,
                'planned_start_at' => $planned->plannedStartAt,
                'planned_end_at' => $planned->plannedEndAt,
                'delay_minutes' => $planned->delayMinutes,
                'max_serials' => $planned->maxSerials(),
                'online_quota' => $planned->onlineQuota,
                'counter_quota' => $planned->counterQuota,
                'buffer_quota' => $planned->bufferQuota,
                'avg_consult_seconds' => $planned->avgConsultSeconds,
                'consult_samples' => 0,
                'auto_noshow_after' => $planned->autoNoshowAfter,
                'fee_new_paisa' => $planned->feeNewPaisa,
                'fee_followup_paisa' => $planned->feeFollowupPaisa,
                'version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($affected === 1) {
                // rowcount 1 => we own creation: insert the three pools in this same transaction
                $id = (int) DB::table('session_instances')->where('public_id', $publicId)->value('id');
                PoolLayout::createFor($id, $planned->counterQuota, $planned->onlineQuota, $planned->bufferQuota);
                $this->stampApplied($planned->overrideIds);
                $wasCreated = true;

                /** @var SessionInstance $instance */
                $instance = SessionInstance::query()->findOrFail($id);

                return $instance;
            }

            // rowcount 0 => another transaction created it and committed; the instance AND its pools are visible now.
            /** @var SessionInstance $instance */
            $instance = SessionInstance::query()->forDay($branchId, $doctorId, $date)->where('session_code', $planned->sessionCode)->firstOrFail();

            return $instance;
        });
    }

    /** @param  array<int, int>  $ids */
    private function stampApplied(array $ids): void
    {
        if ($ids !== []) {
            ScheduleOverride::query()->whereIn('id', $ids)->whereNull('applied_at')->update(['applied_at' => now()]);
        }
    }
}
