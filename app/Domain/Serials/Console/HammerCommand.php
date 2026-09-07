<?php

declare(strict_types=1);

namespace App\Domain\Serials\Console;

use App\Domain\Serials\Actions\AllocateFromBlock;
use App\Domain\Serials\Actions\AllocateSerial;
use App\Domain\Serials\Actions\LeaseBlock;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Shared\Actor;
use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Central\Tenant;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * serials:hammer — the concurrency worker of CONVENTIONS §6.5 / SERIAL_ENGINE §18.1: allocates --count serials in a
 * tight loop with 0–5 ms jitter and prints one JSON line per result. Dev/testing only. --skip-owner-lock is honoured
 * only in the testing environment (the "unique index holds without the lock" proof). --block/--device replays the
 * block's numbers in order through AllocateFromBlock, like a device syncing its offline log.
 */
final class HammerCommand extends Command
{
    protected $signature = 'serials:hammer {--tenant= : Tenant id} {--session= : session_instances.id} {--pool=counter : online|counter|buffer} {--count=25} {--source= : online|counter|walkin|kiosk|followup} {--client-event-id= : Send the same idempotency key every time} {--skip-owner-lock : testing only} {--block= : serial_blocks.id to replay from} {--device= : reception_device_id owning the block} {--priority=normal} {--lease= : Lease a block of this size for --device instead of allocating} {--start-at= : Unix timestamp (float) to wait for before the first allocation, so parallel workers start together}';

    protected $description = 'Hammer AllocateSerial from one process (used by tests/Concurrency via ProcessPool)';

    public function handle(AllocateSerial $allocate, AllocateFromBlock $fromBlock, LeaseBlock $lease): int
    {
        if (app()->isProduction()) {
            $this->components->error('serials:hammer refuses to run in production.');

            return self::FAILURE;
        }

        if ((bool) $this->option('skip-owner-lock') && app()->environment('testing')) {
            config(['serials.testing_skip_owner_lock' => true]);
        }

        $tenant = Tenant::query()->findOrFail((int) $this->option('tenant'));
        $sessionId = (int) $this->option('session');
        $count = (int) $this->option('count');
        $pool = SerialPool::from((string) $this->option('pool'));
        $source = $this->option('source') !== null ? SerialSource::from((string) $this->option('source')) : match ($pool) {
            SerialPool::Online => SerialSource::Online,
            SerialPool::Counter => SerialSource::Counter,
            SerialPool::Buffer => SerialSource::Walkin,
        };
        $priority = SerialPriority::from((string) $this->option('priority'));
        $clientEventId = $this->option('client-event-id');
        $blockId = $this->option('block') !== null ? (int) $this->option('block') : null;
        $deviceId = $this->option('device') !== null ? (int) $this->option('device') : null;

        $failures = 0;
        $this->waitForStart();

        if ($this->option('lease') !== null && $deviceId !== null) {
            return Tenancy::run($tenant, function () use ($lease, $sessionId, $deviceId): int {
                usleep(random_int(0, 5000));

                try {
                    /** @var SessionInstance $session */
                    $session = SessionInstance::query()->findOrFail($sessionId);
                    $block = $lease->handle($session, $deviceId, (int) $this->option('lease'), Actor::system(), (int) $this->option('lease'));
                    $this->line(json_encode(['ok' => true, 'block_id' => $block->id, 'device' => $deviceId, 'range' => [$block->range_start, $block->range_end]], JSON_THROW_ON_ERROR));

                    return self::SUCCESS;
                } catch (DomainException $e) {
                    $this->line(json_encode(['ok' => false, 'code' => $e->code(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));

                    return self::FAILURE;
                }
            });
        }

        Tenancy::run($tenant, function () use ($allocate, $fromBlock, $sessionId, $count, $pool, $source, $priority, $clientEventId, $blockId, $deviceId, &$failures): void {
            for ($i = 0; $i < $count; $i++) {
                usleep(random_int(0, 5000));
                $started = hrtime(true);

                try {
                    if ($blockId !== null && $deviceId !== null) {
                        /** @var SerialBlock $block */
                        $block = SerialBlock::query()->findOrFail($blockId);
                        $number = $block->next_number;
                        $serial = $fromBlock->handle($deviceId, $block, $number, new AllocationRequest(
                            sessionInstanceId: $sessionId, pool: SerialPool::Counter, source: SerialSource::Offline, priority: $priority,
                            clientEventId: sprintf('%s', str_pad(strtoupper(dechex($blockId)), 6, '0', STR_PAD_LEFT).str_pad(strtoupper(dechex($number)), 6, '0', STR_PAD_LEFT).str_pad((string) getmypid(), 14, '0', STR_PAD_LEFT)),
                        ));
                    } else {
                        $serial = $allocate(new AllocationRequest(
                            sessionInstanceId: $sessionId, pool: $pool, source: $source, priority: $priority,
                            clientEventId: is_string($clientEventId) && $clientEventId !== '' ? $clientEventId : null,
                        ));
                    }

                    $this->line(json_encode(['ok' => true, 'number' => $serial->number, 'pool' => $serial->pool->value, 'serial_id' => $serial->id, 'ms' => round((hrtime(true) - $started) / 1e6, 2)], JSON_THROW_ON_ERROR));
                } catch (DomainException $e) {
                    $failures++;
                    $this->line(json_encode(['ok' => false, 'code' => $e->code(), 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
                } catch (Throwable $e) {
                    $failures++;
                    $this->line(json_encode(['ok' => false, 'code' => 'error', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
                }
            }
        });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** Booting eight PHP processes staggers them by hundreds of ms; the barrier makes the allocation loops actually overlap. */
    private function waitForStart(): void
    {
        $startAt = $this->option('start-at');

        if (! is_string($startAt) || $startAt === '') {
            return;
        }

        $wait = (float) $startAt - microtime(true);

        if ($wait > 0) {
            usleep((int) min(30_000_000, $wait * 1_000_000));
        }
    }
}
