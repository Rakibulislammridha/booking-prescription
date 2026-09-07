<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Scheduling\Data\ResyncResult;
use App\Domain\Scheduling\Data\ScheduleOverrideData;
use App\Domain\Scheduling\Enums\OverrideType;
use App\Domain\Scheduling\Exceptions\InvalidOverride;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Serials\Actions\CancelSession;
use App\Domain\Serials\Actions\DelaySession;
use App\Domain\Serials\Actions\ExtendSessionCapacity;
use App\Domain\Shared\Actor;
use App\Models\Tenant\ScheduleOverride;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Records the override and, when the instance already exists, applies it immediately (SERIAL_ENGINE §2.1):
 * cancelled → CancelSession; late_start → DelaySession; cut_short/time_change/capacity_change → resync when the
 * instance is untouched, else (capacity_change growing the buffer) ExtendSessionCapacity, else left for materialisation;
 * extra_session → the instance is created now.
 */
final class CreateScheduleOverride
{
    public function __construct(
        private readonly SessionMaterialiser $materialiser,
        private readonly CancelSession $cancelSession,
        private readonly DelaySession $delaySession,
        private readonly ExtendSessionCapacity $extend,
    ) {}

    /** @return array{override: ScheduleOverride, applied: array<string, string>} per session code: applied|cancelled|delayed|extended|created|refused_has_serials|refused_has_blocks|pending */
    public function handle(ScheduleOverrideData $data, Actor $actor): array
    {
        self::validate($data);

        $override = DB::transaction(fn () => ScheduleOverride::query()->create($data->toAttributes() + ['created_by_user_id' => $actor->userId]));

        $applied = [];
        $instances = SessionInstance::query()
            ->where('doctor_id', $data->doctorId)->where('branch_id', $data->branchId)
            ->whereDate('session_date', $data->overrideDate->toDateString())
            ->when($data->sessionCode !== null, fn ($q) => $q->where('session_code', $data->sessionCode))
            ->open()
            ->get();

        foreach ($instances as $instance) {
            $applied[$instance->session_code] = $this->applyTo($instance, $override, $data, $actor);
        }

        if ($data->type === OverrideType::ExtraSession && $data->sessionCode !== null && ! isset($applied[$data->sessionCode])) {
            $created = $this->materialiser->ensure($data->branchId, $data->doctorId, $data->overrideDate, $data->sessionCode);
            $applied[$data->sessionCode] = $created === null ? 'pending' : 'created';
        }

        return ['override' => $override->refresh(), 'applied' => $applied];
    }

    private function applyTo(SessionInstance $instance, ScheduleOverride $override, ScheduleOverrideData $data, Actor $actor): string
    {
        $stamp = fn () => $override->forceFill(['applied_at' => now()])->save();

        switch ($data->type) {
            case OverrideType::Cancelled:
                $this->cancelSession->handle($instance, $actor, $data->reason ?? 'override', $data->notifyPatients);
                $stamp();

                return 'cancelled';
            case OverrideType::LateStart:
                $this->delaySession->handle($instance, (int) ($data->delayMinutes ?? 0), $actor, $data->reason);
                $stamp();

                return 'delayed';
            case OverrideType::CapacityChange:
                $result = $this->materialiser->resync($instance, $actor);

                if ($result->applied()) {
                    return $result->value;
                }

                $delta = ($data->newBufferQuota ?? $instance->buffer_quota) - $instance->buffer_quota;

                if ($delta > 0) {
                    $this->extend->handle($instance, $delta, $actor, $data->reason ?? 'override');
                    $stamp();

                    return 'extended';
                }

                return $result->value;
            default:
                $result = $this->materialiser->resync($instance, $actor);

                return $result === ResyncResult::NotFound ? 'pending' : $result->value;
        }
    }

    public static function validate(ScheduleOverrideData $data): void
    {
        $needsTimes = in_array($data->type, [OverrideType::TimeChange, OverrideType::ExtraSession], true);

        if ($needsTimes && ($data->newStartTime === null || $data->newEndTime === null)) {
            throw new InvalidOverride('new_start_time and new_end_time are required for this override type.');
        }

        if ($data->type === OverrideType::CutShort && $data->newEndTime === null) {
            throw new InvalidOverride('new_end_time is required to cut a session short.');
        }

        if ($data->type === OverrideType::LateStart && ($data->delayMinutes === null || $data->delayMinutes < 0)) {
            throw new InvalidOverride('delay_minutes is required for a late start.');
        }

        if ($data->type === OverrideType::ExtraSession && $data->sessionCode === null) {
            throw new InvalidOverride('An extra session needs a session_code.');
        }

        if ($data->type === OverrideType::CapacityChange && ($data->newCounterQuota === null || $data->newOnlineQuota === null || $data->newBufferQuota === null)) {
            throw new InvalidOverride('All three quotas are required for a capacity change.');
        }

        if ($data->newStartTime !== null && $data->newEndTime !== null && $data->newEndTime <= $data->newStartTime) {
            throw new InvalidOverride('new_end_time must be after new_start_time.');
        }
    }
}
