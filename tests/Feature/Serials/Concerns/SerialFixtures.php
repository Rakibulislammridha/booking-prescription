<?php

declare(strict_types=1);

namespace Tests\Feature\Serials\Concerns;

use App\Domain\Serials\Actions\AllocateSerial;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The shared fixture of SERIAL_ENGINE §18: a doctor with one weekly template (C=10, O=10, B=5 unless stated) inside
 * the current tenant, plus allocation shortcuts. (Lives under tests/Feature/Serials because tests/Support is
 * foundation-owned.)
 */
trait SerialFixtures
{
    protected function mainBranch(): Branch
    {
        return Branch::query()->where('is_main', true)->firstOrFail();
    }

    /** Doctor + profile + a weekly template on every weekday (so any date materialises). */
    protected function doctorWithTemplate(int $counter = 10, int $online = 10, int $buffer = 5, string $code = 'A'): Doctor
    {
        $doctor = Doctor::factory()->complete()->create();

        foreach (range(0, 6) as $weekday) {
            DoctorSchedule::factory()->quotas($counter, $online, $buffer)->create([
                'doctor_id' => $doctor->id, 'branch_id' => $this->mainBranch()->id, 'weekday' => $weekday, 'session_code' => $code,
            ]);
        }

        return $doctor;
    }

    /** An open instance for today with its pools (no template needed). */
    protected function openSession(int $counter = 10, int $online = 10, int $buffer = 5, ?Doctor $doctor = null): SessionInstance
    {
        return SessionInstance::factory()->openToday()->quotas($counter, $online, $buffer)->create([
            'doctor_id' => ($doctor ?? Doctor::factory()->complete()->create())->id,
            'branch_id' => $this->mainBranch()->id,
        ]);
    }

    protected function allocate(SessionInstance $session, SerialPool $pool = SerialPool::Counter, ?SerialSource $source = null, SerialPriority $priority = SerialPriority::Normal, ?string $clientEventId = null, ?int $patientId = null, ?CarbonImmutable $slot = null, ?int $actorUserId = null, ?string $priorityReason = null): Serial
    {
        $source ??= match ($pool) {
            SerialPool::Online => SerialSource::Online,
            SerialPool::Counter => SerialSource::Counter,
            SerialPool::Buffer => SerialSource::Walkin,
        };

        return app(AllocateSerial::class)(new AllocationRequest(
            sessionInstanceId: $session->id, pool: $pool, source: $source, priority: $priority, patientId: $patientId,
            clientEventId: $clientEventId, actorUserId: $actorUserId, slotStartAt: $slot, priorityReason: $priorityReason,
        ));
    }

    /** @return array<int, Serial> */
    protected function allocateMany(SessionInstance $session, int $n, SerialPool $pool = SerialPool::Counter): array
    {
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->allocate($session, $pool);
        }

        return $out;
    }

    /**
     * reception_devices rows for the bare device ids these tests lease with (serial_blocks.reception_device_id is a
     * FK since the Reception module's 2026_02_03_000500 migration). Explicit ids keep the assertions literal; the
     * sequence is bumped past them so an implicit insert later in the same test never collides.
     */
    protected function ensureDevices(int ...$ids): void
    {
        foreach ($ids as $id) {
            if (ReceptionDevice::query()->whereKey($id)->doesntExist()) {
                ReceptionDevice::factory()->create(['id' => $id, 'name' => "Device {$id}", 'branch_id' => $this->mainBranch()->id]);
            }
        }

        DB::statement("select setval(pg_get_serial_sequence('reception_devices', 'id'), greatest((select max(id) from reception_devices), 1))");
    }

    protected function staffActor(): Actor
    {
        return Actor::user(1, 'receptionist');
    }

    protected function today(): CarbonImmutable
    {
        return Clock::today();
    }
}
