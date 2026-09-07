<?php

declare(strict_types=1);

namespace Tests\Feature\Queue\Concerns;

use App\Domain\Serials\Actions\AllocateSerial;
use App\Domain\Serials\Actions\CallNext;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Carbon\CarbonImmutable;

/**
 * The Queue module's fixture: a doctor with a slug, one open session today and shortcuts for issuing / checking in /
 * calling serials through the real Serials actions, so every test exercises the domain events the listeners hang on.
 * (tests/Support is foundation-owned, hence a module-local trait.)
 */
trait QueueFixtures
{
    protected function mainBranch(): Branch
    {
        return Branch::query()->where('is_main', true)->firstOrFail();
    }

    protected function queueDoctor(string $slug = 'dr-rahman', ?string $room = 'Room 3'): Doctor
    {
        return Doctor::factory()->complete()->create(['slug' => $slug, 'room_label' => $room]);
    }

    protected function queueSession(?Doctor $doctor = null, string $code = 'A', int $counter = 10, int $online = 10, int $buffer = 5, string $start = '09:00', string $end = '13:00'): SessionInstance
    {
        $today = Clock::today();

        return SessionInstance::factory()->openToday()->quotas($counter, $online, $buffer)->create([
            'doctor_id' => ($doctor ?? $this->queueDoctor())->id,
            'branch_id' => $this->mainBranch()->id,
            'session_code' => $code,
            'planned_start_at' => $today->setTimeFromTimeString($start)->utc(),
            'planned_end_at' => $today->setTimeFromTimeString($end)->utc(),
        ]);
    }

    protected function issue(SessionInstance $session, ?int $patientId = null, SerialPool $pool = SerialPool::Counter): Serial
    {
        return app(AllocateSerial::class)(new AllocationRequest(
            sessionInstanceId: $session->id,
            pool: $pool,
            source: $pool === SerialPool::Online ? SerialSource::Online : SerialSource::Counter,
            patientId: $patientId,
        ));
    }

    /** @return array<int, Serial> */
    protected function issueMany(SessionInstance $session, int $n, bool $withPatients = false): array
    {
        $out = [];

        for ($i = 0; $i < $n; $i++) {
            $out[] = $this->issue($session, $withPatients ? Patient::factory()->create()->id : null);
        }

        return $out;
    }

    protected function checkIn(Serial $serial): Serial
    {
        return app(CheckInSerial::class)->handle($serial->fresh() ?? $serial, $this->queueActor());
    }

    protected function callNext(SessionInstance $session): ?Serial
    {
        return app(CallNext::class)->handle($session->fresh() ?? $session, $this->queueActor())['called'];
    }

    protected function queueActor(): Actor
    {
        return Actor::user(1, 'receptionist');
    }

    protected function today(): CarbonImmutable
    {
        return Clock::today();
    }
}
