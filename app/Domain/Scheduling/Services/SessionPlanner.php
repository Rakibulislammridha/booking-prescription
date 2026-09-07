<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Services;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Scheduling\Data\PlannedSession;
use App\Domain\Scheduling\Enums\OverrideType;
use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\DoctorProfile;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\ScheduleOverride;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Derives the sessions of one (branch, doctor, date) from the four sources with the precedence of SERIAL_ENGINE §2.1:
 * overrides > leaves > holidays > weekly template. An explicit non-cancelled override opens a session on a leave or
 * holiday day; `works_on_holidays` lets the template ignore holidays; `extra_session` creates a session on an off day.
 */
final class SessionPlanner
{
    public const DEFAULT_AVG_MINUTES = 6;

    public function __construct(private readonly Settings $settings) {}

    /** @return array<string, PlannedSession> keyed by session_code, ascending */
    public function planFor(int $branchId, int $doctorId, CarbonImmutable $date): array
    {
        $date = $date->setTimezone(Clock::timezone())->startOfDay();

        $templates = DoctorSchedule::query()
            ->where('doctor_id', $doctorId)->where('branch_id', $branchId)
            ->active()->effectiveOn($date)
            ->orderBy('session_code')->orderByDesc('effective_from')
            ->get()
            ->unique('session_code')
            ->keyBy('session_code');

        $onLeave = DoctorLeave::query()
            ->where('doctor_id', $doctorId)->where('is_cancelled', false)
            ->whereDate('starts_on', '<=', $date->toDateString())->whereDate('ends_on', '>=', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->exists();

        $holiday = Holiday::query()
            ->whereDate('holiday_date', $date->toDateString())
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId))
            ->exists();

        $overrides = ScheduleOverride::query()
            ->where('doctor_id', $doctorId)->where('branch_id', $branchId)
            ->whereDate('override_date', $date->toDateString())
            ->orderBy('id')
            ->get();

        $profile = DoctorProfile::query()->where('doctor_id', $doctorId)->first();

        return $this->plan($templates, $overrides, $onLeave, $holiday, $date, $profile);
    }

    /**
     * Pure precedence over already-loaded rows (unit-testable).
     *
     * @param  Collection<string, DoctorSchedule>  $templates  keyed by session_code
     * @param  Collection<int, ScheduleOverride>  $overrides
     * @return array<string, PlannedSession>
     */
    public function plan(Collection $templates, Collection $overrides, bool $onLeave, bool $holiday, CarbonImmutable $date, ?DoctorProfile $profile = null): array
    {
        $codes = $templates->keys()->all();

        foreach ($overrides as $o) {
            if ($o->type === OverrideType::ExtraSession && $o->session_code !== null) {
                $codes[] = $o->session_code;
            }
        }

        $codes = array_values(array_unique($codes));
        sort($codes);

        $defaultNoShow = (int) $this->settings->get('queue.auto_noshow_after');
        $out = [];

        foreach ($codes as $code) {
            $template = $templates->get($code);
            $applicable = $overrides->filter(fn (ScheduleOverride $o) => $o->appliesTo($code))->values();
            $cancelled = $applicable->contains(fn (ScheduleOverride $o) => $o->type === OverrideType::Cancelled);

            if ($cancelled) {
                continue;
            }

            $explicit = $applicable->isNotEmpty();
            $worksOnHolidays = $template !== null && $template->works_on_holidays;

            if ($onLeave && ! $explicit) {
                continue;
            }

            if ($holiday && ! $worksOnHolidays && ! $explicit) {
                continue;
            }

            $planned = $template !== null ? $this->fromTemplate($template, $date, $defaultNoShow, $profile) : null;

            foreach ($applicable as $o) {
                $planned = $this->applyOverride($planned, $o, $code, $date, $defaultNoShow, $profile);
            }

            if ($planned !== null) {
                $out[$code] = $planned;
            }
        }

        return $out;
    }

    private function fromTemplate(DoctorSchedule $t, CarbonImmutable $date, int $defaultNoShow, ?DoctorProfile $profile): PlannedSession
    {
        return new PlannedSession(
            sessionCode: $t->session_code,
            plannedStartAt: self::at($date, $t->start_time),
            plannedEndAt: self::at($date, $t->end_time),
            mode: $t->mode,
            slotMinutes: $t->mode === ScheduleMode::Slot ? $t->slot_minutes : null,
            counterQuota: $t->counter_quota,
            onlineQuota: $t->online_quota,
            bufferQuota: $t->buffer_quota,
            avgConsultSeconds: max(60, $t->avg_consult_minutes * 60),
            autoNoshowAfter: $t->auto_noshow_after ?? $defaultNoShow,
            feeNewPaisa: $t->fee_new_paisa ?? ($profile === null ? 0 : $profile->new_fee_paisa),
            feeFollowupPaisa: $t->fee_followup_paisa ?? ($profile === null ? 0 : $profile->followup_fee_paisa),
            doctorScheduleId: $t->id,
        );
    }

    private function applyOverride(?PlannedSession $p, ScheduleOverride $o, string $code, CarbonImmutable $date, int $defaultNoShow, ?DoctorProfile $profile): ?PlannedSession
    {
        $ids = array_merge($p === null ? [] : $p->overrideIds, [$o->id]);

        if ($o->type === OverrideType::ExtraSession) {
            if ($o->session_code !== $code) {
                return $p;
            }

            $start = $o->new_start_time ?? ($p === null ? '09:00:00' : $p->plannedStartAt->setTimezone(Clock::timezone())->format('H:i:s'));
            $end = $o->new_end_time ?? ($p === null ? '13:00:00' : $p->plannedEndAt->setTimezone(Clock::timezone())->format('H:i:s'));

            return new PlannedSession(
                sessionCode: $code,
                plannedStartAt: self::at($date, $start),
                plannedEndAt: self::at($date, $end),
                mode: $p === null ? ScheduleMode::Serial : $p->mode,
                slotMinutes: $p === null ? null : $p->slotMinutes,
                counterQuota: $o->new_counter_quota ?? ($p === null ? 0 : $p->counterQuota),
                onlineQuota: $o->new_online_quota ?? ($p === null ? 0 : $p->onlineQuota),
                bufferQuota: $o->new_buffer_quota ?? ($p === null ? 4 : $p->bufferQuota),
                avgConsultSeconds: $p === null ? self::DEFAULT_AVG_MINUTES * 60 : $p->avgConsultSeconds,
                autoNoshowAfter: $p === null ? $defaultNoShow : $p->autoNoshowAfter,
                feeNewPaisa: $p === null ? ($profile === null ? 0 : $profile->new_fee_paisa) : $p->feeNewPaisa,
                feeFollowupPaisa: $p === null ? ($profile === null ? 0 : $profile->followup_fee_paisa) : $p->feeFollowupPaisa,
                doctorScheduleId: $p === null ? null : $p->doctorScheduleId,
                delayMinutes: $p === null ? 0 : $p->delayMinutes,
                overrideIds: $ids,
            );
        }

        if ($p === null) {
            return null;   // a modification of a session the template does not have on this day
        }

        return match ($o->type) {
            OverrideType::LateStart => $p->with(['delayMinutes' => max(0, (int) ($o->delay_minutes ?? 0)), 'overrideIds' => $ids]),
            OverrideType::CutShort => $p->with(['plannedEndAt' => $o->new_end_time === null ? $p->plannedEndAt : self::at($date, $o->new_end_time), 'overrideIds' => $ids]),
            OverrideType::TimeChange => $p->with([
                'plannedStartAt' => $o->new_start_time === null ? $p->plannedStartAt : self::at($date, $o->new_start_time),
                'plannedEndAt' => $o->new_end_time === null ? $p->plannedEndAt : self::at($date, $o->new_end_time),
                'overrideIds' => $ids,
            ]),
            OverrideType::CapacityChange => $p->with([
                'counterQuota' => $o->new_counter_quota ?? $p->counterQuota,
                'onlineQuota' => $o->new_online_quota ?? $p->onlineQuota,
                'bufferQuota' => $o->new_buffer_quota ?? $p->bufferQuota,
                'overrideIds' => $ids,
            ]),
            default => $p,
        };
    }

    /** Tenant-local wall clock on a calendar date → UTC instant. */
    public static function at(CarbonImmutable $date, string $time): CarbonImmutable
    {
        [$h, $m, $s] = array_map('intval', array_pad(explode(':', $time), 3, '0'));

        return $date->setTimezone(Clock::timezone())->setTime($h, $m, $s)->utc();
    }
}
