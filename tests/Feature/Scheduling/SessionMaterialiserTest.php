<?php

declare(strict_types=1);

namespace Tests\Feature\Scheduling;

use App\Domain\Clinic\Actions\CreateDoctorLeave;
use App\Domain\Clinic\Data\DoctorLeaveData;
use App\Domain\Clinic\Enums\LeaveType;
use App\Domain\Scheduling\Actions\CreateDoctorSchedule;
use App\Domain\Scheduling\Actions\CreateScheduleOverride;
use App\Domain\Scheduling\Actions\DeleteScheduleOverride;
use App\Domain\Scheduling\Actions\UpdateDoctorSchedule;
use App\Domain\Scheduling\Data\DoctorScheduleData;
use App\Domain\Scheduling\Data\ResyncResult;
use App\Domain\Scheduling\Data\ScheduleOverrideData;
use App\Domain\Scheduling\Enums\OverrideType;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Scheduling\Exceptions\ScheduleConflict;
use App\Domain\Scheduling\Jobs\MaterialiseTenantSessions;
use App\Domain\Scheduling\Services\AvailabilityCalendar;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Serials\Actions\LeaseBlock;
use App\Domain\Serials\Actions\ReleaseBlock;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\ScheduleOverride;
use App\Models\Tenant\SerialPool;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** SERIAL_ENGINE §18.5 + the template/override actions. */
final class SessionMaterialiserTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    private function materialiser(): SessionMaterialiser
    {
        return app(SessionMaterialiser::class);
    }

    public function test_weekly_template_creates_instances_and_three_pools_with_correct_ranges(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $date = $this->today()->addDays(2);

        $instance = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A');

        $this->assertNotNull($instance);
        $this->assertSame($date->toDateString(), $instance->session_date->toDateString());
        $this->assertSame(SessionStatus::Scheduled, $instance->status);
        $this->assertSame(25, $instance->max_serials);
        $this->assertSame(360, $instance->avg_consult_seconds);
        $this->assertSame(3, $instance->auto_noshow_after);
        $this->assertSame(80000, $instance->fee_new_paisa);
        $this->assertSame(50000, $instance->fee_followup_paisa);
        $this->assertSame($date->setTimezone(Clock::timezone())->setTime(9, 0)->utc()->toIso8601String(), $instance->planned_start_at->utc()->toIso8601String());
        $this->assertNotNull($instance->doctor_schedule_id);

        $pools = SerialPool::query()->where('session_instance_id', $instance->id)->get()->keyBy(fn (SerialPool $p) => $p->pool->value);
        $this->assertSame([1, 10], [$pools['counter']->range_start, $pools['counter']->range_end]);
        $this->assertSame([11, 20], [$pools['online']->range_start, $pools['online']->range_end]);
        $this->assertSame([21, 25], [$pools['buffer']->range_start, $pools['buffer']->range_end]);

        $this->assertSame($instance->id, $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A')?->id, 'idempotent');
        $this->assertNull($this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'B'), 'no template → no instance');
        $this->assertCount(1, $this->materialiser()->ensureDay($this->mainBranch()->id, $doctor->id, $date));
    }

    public function test_holiday_suppresses_unless_works_on_holidays(): void
    {
        $doctor = $this->doctorWithTemplate();
        $date = $this->today()->addDays(3);
        Holiday::factory()->create(['holiday_date' => $date->toDateString(), 'branch_id' => null]);

        $this->assertNull($this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A'));

        DoctorSchedule::query()->where('doctor_id', $doctor->id)->update(['works_on_holidays' => true]);
        $this->assertNotNull($this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A'));
    }

    public function test_leave_suppresses_and_cancels_existing_instances(): void
    {
        $doctor = $this->doctorWithTemplate();
        $date = $this->today()->addDays(4);
        $existing = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $this->today()->addDays(5), 'A');

        app(CreateDoctorLeave::class)->handle(new DoctorLeaveData(doctorId: $doctor->id, startsOn: $date, endsOn: $date->addDays(2), type: LeaveType::Emergency), Actor::system());

        $this->assertNull($this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A'));
        $this->assertSame(SessionStatus::Cancelled, $existing?->fresh()->status, 'DoctorLeaveCreated cancels instances in range');
        $this->assertSame('emergency_leave', $existing->fresh()->cancel_reason);
        $this->assertNotNull($this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date->addDays(3), 'A'), 'after the leave');
    }

    public function test_override_cancelled_suppresses_and_override_extra_creates_on_off_day(): void
    {
        $doctor = $this->doctorWithTemplate();
        $date = $this->today()->addDays(6);
        ScheduleOverride::factory()->cancelled()->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'override_date' => $date->toDateString(), 'session_code' => 'A']);
        $this->assertNull($this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A'));

        $off = $this->today()->addDays(7);
        DoctorSchedule::query()->where('doctor_id', $doctor->id)->where('weekday', $off->dayOfWeek)->delete();
        Holiday::factory()->create(['holiday_date' => $off->toDateString(), 'branch_id' => null]);
        $override = ScheduleOverride::factory()->extraSession('C', '15:00:00', '17:00:00', 8, 4, 2)->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'override_date' => $off->toDateString()]);

        $extra = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $off, 'C');
        $this->assertNotNull($extra, 'overrides win over holidays');
        $this->assertNull($extra->doctor_schedule_id);
        $this->assertSame(14, $extra->max_serials);
        $this->assertSame(8, $extra->counter_quota);
        $this->assertSame($off->setTimezone(Clock::timezone())->setTime(15, 0)->utc()->getTimestamp(), $extra->planned_start_at->getTimestamp());
        $this->assertNotNull($override->fresh()->applied_at);
        $this->assertNull($this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $off, 'A'));
    }

    public function test_override_modified_changes_times_quotas_and_delay(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $date = $this->today()->addDays(8);
        ScheduleOverride::factory()->timeChange('10:00:00', '12:00:00')->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'override_date' => $date->toDateString(), 'session_code' => 'A']);
        ScheduleOverride::factory()->capacityChange(12, 6, 2)->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'override_date' => $date->toDateString(), 'session_code' => null]);
        ScheduleOverride::factory()->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'override_date' => $date->toDateString(), 'session_code' => 'A', 'type' => OverrideType::LateStart, 'delay_minutes' => 20]);

        $instance = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A');

        $this->assertNotNull($instance);
        $this->assertSame($date->setTimezone(Clock::timezone())->setTime(10, 0)->utc()->getTimestamp(), $instance->planned_start_at->getTimestamp());
        $this->assertSame($date->setTimezone(Clock::timezone())->setTime(12, 0)->utc()->getTimestamp(), $instance->planned_end_at->getTimestamp());
        $this->assertSame([12, 6, 2, 20], [$instance->counter_quota, $instance->online_quota, $instance->buffer_quota, $instance->max_serials]);
        $this->assertSame(20, $instance->delay_minutes);
        $this->assertSame(0, ScheduleOverride::query()->whereNull('applied_at')->where('doctor_id', $doctor->id)->count());
        $this->assertSame([13, 18], SerialPool::query()->where('session_instance_id', $instance->id)->where('pool', 'online')->get(['range_start', 'range_end'])->map(fn (SerialPool $p) => [$p->range_start, $p->range_end])->first());
    }

    public function test_resync_refused_when_serials_or_blocks_exist(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $date = $this->today()->addDays(9);
        $instance = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A');
        $this->assertNotNull($instance);

        DoctorSchedule::query()->where('doctor_id', $doctor->id)->update(['counter_quota' => 15, 'max_serials' => 30, 'start_time' => '10:00:00']);
        $this->assertSame(ResyncResult::Applied, $this->materialiser()->resync($instance));
        $fresh = $instance->fresh();
        $this->assertSame(30, $fresh->max_serials);
        $this->assertSame(15, SerialPool::query()->where('session_instance_id', $instance->id)->where('pool', 'counter')->value('range_end'));
        $this->assertSame(2, $fresh->version);

        $this->ensureDevices(1);
        $block = app(LeaseBlock::class)->handle($fresh, 1, 5, Actor::system(), 5);
        $this->assertSame(ResyncResult::RefusedHasBlocks, $this->materialiser()->resync($fresh));
        app(ReleaseBlock::class)->handle($block, Actor::system());
        $this->allocate($fresh);
        $this->assertSame(ResyncResult::RefusedHasSerials, $this->materialiser()->resync($fresh));

        $untouched = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date->addDay(), 'A');
        ScheduleOverride::factory()->cancelled()->create(['doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'override_date' => $date->addDay()->toDateString()]);
        $this->assertSame(ResyncResult::Cancelled, $this->materialiser()->resync($untouched));
        $this->assertSame(SessionStatus::Cancelled, $untouched->fresh()->status);
    }

    public function test_create_override_applies_immediately_to_an_existing_instance(): void
    {
        $doctor = $this->doctorWithTemplate();
        $date = $this->today()->addDays(10);
        $instance = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $date, 'A');
        $this->assertNotNull($instance);

        $delayed = app(CreateScheduleOverride::class)->handle(new ScheduleOverrideData(doctorId: $doctor->id, branchId: $this->mainBranch()->id, overrideDate: $date, type: OverrideType::LateStart, sessionCode: 'A', delayMinutes: 30), Actor::system());
        $this->assertSame(['A' => 'delayed'], $delayed['applied']);
        $this->assertSame(30, $instance->fresh()->delay_minutes);
        $this->assertNotNull($delayed['override']->applied_at);

        $this->allocate($instance);
        $extended = app(CreateScheduleOverride::class)->handle(new ScheduleOverrideData(doctorId: $doctor->id, branchId: $this->mainBranch()->id, overrideDate: $date, type: OverrideType::CapacityChange, sessionCode: 'A', newCounterQuota: 10, newOnlineQuota: 10, newBufferQuota: 9), Actor::system());
        $this->assertSame(['A' => 'extended'], $extended['applied'], 'a live instance only grows its buffer');
        $this->assertSame(29, $instance->fresh()->max_serials);

        $cancelled = app(CreateScheduleOverride::class)->handle(new ScheduleOverrideData(doctorId: $doctor->id, branchId: $this->mainBranch()->id, overrideDate: $date, type: OverrideType::Cancelled, reason: 'strike'), Actor::system());
        $this->assertSame(['A' => 'cancelled'], $cancelled['applied']);
        $this->assertSame(SessionStatus::Cancelled, $instance->fresh()->status);
        $this->assertSame(SerialStatus::Cancelled, $instance->serials()->first()?->status);

        app(DeleteScheduleOverride::class)->handle($cancelled['override'], Actor::system());
        $this->assertSame(SessionStatus::Cancelled, $instance->fresh()->status, 'a cancelled instance stays cancelled');
    }

    public function test_template_crud_refuses_overlaps_and_resyncs_untouched_instances(): void
    {
        $doctor = Doctor::factory()->complete()->create();
        $data = new DoctorScheduleData(doctorId: $doctor->id, branchId: $this->mainBranch()->id, weekday: 1, sessionCode: 'A', startTime: '09:00:00', endTime: '13:00:00', counterQuota: 10, onlineQuota: 10, bufferQuota: 5);
        $a = app(CreateDoctorSchedule::class)->handle($data, Actor::system());
        $this->assertSame(25, $a->max_serials);

        $this->assertThrows(fn () => app(CreateDoctorSchedule::class)->handle(new DoctorScheduleData(doctorId: $doctor->id, branchId: $this->mainBranch()->id, weekday: 1, sessionCode: 'B', startTime: '12:00:00', endTime: '14:00:00', counterQuota: 5, onlineQuota: 5), Actor::system()), ScheduleConflict::class);
        $b = app(CreateDoctorSchedule::class)->handle(new DoctorScheduleData(doctorId: $doctor->id, branchId: $this->mainBranch()->id, weekday: 1, sessionCode: 'B', startTime: '17:00:00', endTime: '21:00:00', counterQuota: 5, onlineQuota: 5), Actor::system());

        $monday = $this->today()->next(CarbonImmutable::MONDAY);
        $instances = $this->materialiser()->ensureDay($this->mainBranch()->id, $doctor->id, $monday);
        $this->assertSame(['A', 'B'], $instances->pluck('session_code')->all());

        app(UpdateDoctorSchedule::class)->handle($b, new DoctorScheduleData(doctorId: $doctor->id, branchId: $this->mainBranch()->id, weekday: 1, sessionCode: 'B', startTime: '18:00:00', endTime: '21:00:00', counterQuota: 6, onlineQuota: 6, bufferQuota: 3), Actor::system());
        $evening = SessionInstance::query()->where('doctor_schedule_id', $b->id)->firstOrFail();
        $this->assertSame(15, $evening->max_serials);
        $this->assertSame($monday->setTimezone(Clock::timezone())->setTime(18, 0)->utc()->getTimestamp(), $evening->planned_start_at->getTimestamp());
    }

    public function test_materialise_range_creates_the_horizon_and_the_command_dispatches_per_tenant(): void
    {
        $doctor = $this->doctorWithTemplate();
        $created = $this->materialiser()->materialiseRange($this->today(), $this->today()->addDays(6));
        $this->assertSame(7, $created);
        $this->assertSame(7, SessionInstance::query()->where('doctor_id', $doctor->id)->count());
        $this->assertSame(0, $this->materialiser()->materialiseRange($this->today(), $this->today()->addDays(6)), 'idempotent');

        Bus::fake([MaterialiseTenantSessions::class]);
        $this->asCentral();
        $this->artisan('sessions:materialise', ['--days' => 3])->assertSuccessful();
        Bus::assertDispatched(MaterialiseTenantSessions::class, fn (MaterialiseTenantSessions $job) => $job->tenantId === 9001 && $job->to === Clock::today()->addDays(3)->toDateString());
        Bus::assertDispatched(MaterialiseTenantSessions::class, fn (MaterialiseTenantSessions $job) => $job->tenantId === 9002);
    }

    public function test_close_stale_closes_yesterday(): void
    {
        $doctor = $this->doctorWithTemplate();
        $yesterday = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $this->today()->subDay(), 'A');
        $todaySession = $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $this->today(), 'A');
        $this->assertNotNull($yesterday);
        $this->allocate($yesterday);

        $this->artisan('sessions:close-stale')->assertSuccessful();

        $this->assertSame(SessionStatus::Closed, $yesterday->fresh()->status);
        $this->assertSame('stale', $yesterday->fresh()->notes);
        $this->assertSame(1, $yesterday->fresh()->no_show_count);
        $this->assertSame(SessionStatus::Scheduled, $todaySession?->fresh()->status);
    }

    public function test_availability_calendar_materialises_on_demand_and_reports_online_remaining(): void
    {
        $doctor = $this->doctorWithTemplate(10, 10, 5);
        $from = $this->today()->addDays(11);
        $days = app(AvailabilityCalendar::class)->days($doctor->id, $this->mainBranch()->id, $from, $from->addDays(2));

        $this->assertCount(3, $days);
        $this->assertSame($from->toDateString(), $days[0]['date']);
        $this->assertCount(1, $days[0]['sessions']);
        $this->assertEquals(['code' => 'A', 'online_remaining' => 10, 'status' => 'scheduled', 'mode' => 'serial'], array_intersect_key($days[0]['sessions'][0], ['code' => 1, 'online_remaining' => 1, 'status' => 1, 'mode' => 1]));
        $this->assertSame(3, SessionInstance::query()->where('doctor_id', $doctor->id)->count());
    }

    public function test_scheduling_tables_are_tenant_isolated(): void
    {
        $this->assertTenantIsolated('session_instances', function (): void {
            $doctor = $this->doctorWithTemplate();
            $this->materialiser()->ensure($this->mainBranch()->id, $doctor->id, $this->today(), 'A');
        });
        $this->assertTenantIsolated('doctor_schedules', fn () => $this->doctorWithTemplate());
    }
}
