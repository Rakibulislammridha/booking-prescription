<?php

declare(strict_types=1);

namespace App\Domain\Reception\Console;

use App\Domain\Reception\Sync\SyncReplayer;
use App\Models\Central\Tenant;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * reception:sync-hammer — the ProcessPool worker of tests/Concurrency/Reception: builds a device's offline log
 * (register_patient → issue_serial → check_in per number of its block) and replays it through SyncReplayer, in
 * --batches slices, printing one JSON line per result. Dev/testing only.
 */
final class SyncHammerCommand extends Command
{
    protected $signature = 'reception:sync-hammer {--tenant= : Tenant id} {--device= : reception_devices.id} {--actor= : users.id} {--block= : serial_blocks.id to issue from} {--count= : numbers to issue (default: the whole block)} {--batches=1 : split the log into this many sync calls} {--twice : replay the identical batch twice (exactly-once proof)} {--start-at= : Unix timestamp (float) barrier} {--mobile-prefix=017 : mobile prefix, one household per number}';

    protected $description = 'Replay a synthetic offline event log for one device (used by tests/Concurrency/Reception)';

    public function handle(SyncReplayer $replayer): int
    {
        if (app()->isProduction()) {
            $this->components->error('reception:sync-hammer refuses to run in production.');

            return self::FAILURE;
        }

        $tenant = Tenant::query()->findOrFail((int) $this->option('tenant'));
        $this->waitForStart();
        $failures = 0;

        Tenancy::run($tenant, function () use ($replayer, &$failures): void {
            /** @var ReceptionDevice $device */
            $device = ReceptionDevice::query()->findOrFail((int) $this->option('device'));
            /** @var User $actor */
            $actor = User::query()->findOrFail((int) $this->option('actor'));
            /** @var SerialBlock $block */
            $block = SerialBlock::query()->findOrFail((int) $this->option('block'));
            /** @var SessionInstance $session */
            $session = SessionInstance::query()->findOrFail($block->session_instance_id);

            $count = $this->option('count') !== null ? (int) $this->option('count') : $block->range_end - $block->next_number + 1;
            $events = $this->log($device, $block, $session, $count);
            $batches = max(1, (int) $this->option('batches'));
            $chunks = array_chunk($events, (int) ceil(count($events) / $batches));

            foreach ($chunks as $chunk) {
                $rounds = (bool) $this->option('twice') ? 2 : 1;

                for ($i = 0; $i < $rounds; $i++) {
                    usleep(random_int(0, 5000));

                    try {
                        $result = $replayer->replay($device, $actor, $chunk, 'hammer');

                        foreach ($result->results as $r) {
                            $this->line(json_encode(['ok' => $r['status'] === 'accepted', 'device' => $device->id] + $r, JSON_THROW_ON_ERROR));

                            if ($r['status'] === 'rejected') {
                                $failures++;
                            }
                        }
                    } catch (Throwable $e) {
                        $failures++;
                        $this->line(json_encode(['ok' => false, 'device' => $device->id, 'code' => 'error', 'message' => $e->getMessage()], JSON_THROW_ON_ERROR));
                    }
                }
            }
        });

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array<int, array<string, mixed>> */
    private function log(ReceptionDevice $device, SerialBlock $block, SessionInstance $session, int $count): array
    {
        $events = [];
        $seq = 1;
        $at = now();

        for ($i = 0; $i < $count; $i++) {
            $number = $block->next_number + $i;
            $localId = 'L'.$device->id.'-'.$number;
            $register = (string) Str::ulid();
            $issue = (string) Str::ulid();
            $checkIn = (string) Str::ulid();
            // unique mobile per device+number so no household conflict fires in the hammer
            $mobile = '+88'.$this->option('mobile-prefix').str_pad((string) (($device->id % 90) * 10000 + $number), 8, '0', STR_PAD_LEFT);

            $events[] = ['client_event_id' => $register, 'sequence_no' => $seq++, 'type' => 'register_patient', 'client_occurred_at' => $at->toIso8601String(), 'depends_on' => null,
                'payload' => ['localId' => $localId, 'mobile' => $mobile, 'name' => "Hammer {$device->id}-{$number}", 'sex' => 'f', 'ageYears' => 30]];
            $events[] = ['client_event_id' => $issue, 'sequence_no' => $seq++, 'type' => 'issue_serial', 'client_occurred_at' => $at->toIso8601String(), 'depends_on' => $register, 'session_id' => $session->public_id,
                'payload' => ['sessionId' => $session->public_id, 'blockId' => $block->public_id, 'number' => $number, 'displayCode' => $session->session_code.'-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT), 'patientRef' => 'local:'.$localId, 'priority' => 'normal', 'walkIn' => false, 'appointmentType' => 'new', 'feeSnapshot' => ['amount' => $session->fee_new_paisa, 'currency' => 'BDT']]];
            $events[] = ['client_event_id' => $checkIn, 'sequence_no' => $seq++, 'type' => 'check_in', 'client_occurred_at' => $at->toIso8601String(), 'depends_on' => $issue, 'session_id' => $session->public_id,
                'payload' => ['serialRef' => 'local:'.$issue]];
        }

        return $events;
    }

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
