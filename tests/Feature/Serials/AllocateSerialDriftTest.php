<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Exceptions\AllocationDriftDetected;
use App\Domain\Serials\Exceptions\AllocationRetryExhausted;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SerialPool as PoolRow;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/** SERIAL_ENGINE §18.4: the unique index is defence in depth — a foreign row on the next number is skipped and reported. */
final class AllocateSerialDriftTest extends TestCase
{
    use SerialFixtures;

    /** @var array<int, \Throwable> */
    private array $reported = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');

        $handler = $this->createMock(ExceptionHandler::class);
        $handler->method('report')->willReturnCallback(function (\Throwable $e): void {
            $this->reported[] = $e;
        });
        $handler->method('shouldReport')->willReturn(true);
        $this->app->instance(ExceptionHandler::class, $handler);
    }

    private function plantForeignRow(int $sessionId, int $number): void
    {
        DB::table('serials')->insert([
            'public_id' => sprintf('%026s', 'X'.$number), 'session_instance_id' => $sessionId, 'number' => $number, 'display_code' => 'A-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'position' => $number * 1_000_000, 'pool' => 'counter', 'status' => 'booked', 'priority' => 'normal', 'source' => 'counter',
            'booked_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_unique_violation_is_skipped_and_reported(): void
    {
        $session = $this->openSession();
        $this->plantForeignRow($session->id, 1);

        $serial = $this->allocate($session);

        $this->assertSame(2, $serial->number);
        $this->assertSame(3, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $skipped = SerialEvent::query()->where('session_instance_id', $session->id)->where('type', SerialEventType::NumberSkipped->value)->firstOrFail();
        $this->assertNull($skipped->serial_id);
        $this->assertSame(['number' => 1, 'reason' => 'unique_violation'], array_intersect_key($skipped->meta, ['number' => 1, 'reason' => 1]));
        $this->assertCount(1, $this->reported);
        $this->assertInstanceOf(AllocationDriftDetected::class, $this->reported[0]);
        $this->assertSame(1, $this->reported[0]->number);
        $this->assertSame(2, Serial::query()->where('session_instance_id', $session->id)->count());
    }

    public function test_five_consecutive_violations_throw_retry_exhausted(): void
    {
        $session = $this->openSession();
        foreach (range(1, 5) as $n) {
            $this->plantForeignRow($session->id, $n);
        }

        try {
            $this->allocate($session);
            $this->fail('expected AllocationRetryExhausted');
        } catch (AllocationRetryExhausted $e) {
            $this->assertSame('serials.allocation_retry_exhausted', $e->code());
        }

        $this->assertCount(5, $this->reported);
        // the transaction rolled back: cursor and skip events are gone, the planted rows remain
        $this->assertSame(1, PoolRow::query()->where('session_instance_id', $session->id)->where('pool', 'counter')->value('next_number'));
        $this->assertSame(0, SerialEvent::query()->where('session_instance_id', $session->id)->count());
        $this->assertSame(5, Serial::query()->where('session_instance_id', $session->id)->count());
    }
}
