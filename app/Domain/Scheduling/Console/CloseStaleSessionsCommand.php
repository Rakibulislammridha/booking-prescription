<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Console;

use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Actions\CloseSession;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

/**
 * sessions:close-stale — runs inside a tenant context (`tenants:run sessions:close-stale`, 23:55 Asia/Dhaka):
 * every instance with session_date < today and status in (scheduled, running, paused) goes through CloseSession
 * with reason `stale` (SERIAL_ENGINE §5.1). There is no daily reset job — new dates get fresh instances.
 */
final class CloseStaleSessionsCommand extends Command
{
    protected $signature = 'sessions:close-stale {--before= : Close instances dated before this date (default: today)}';

    protected $description = 'Close every open session instance dated before today (auto no-show the remaining serials, release blocks)';

    public function handle(CloseSession $close): int
    {
        if (! Tenancy::check()) {
            $this->components->error('sessions:close-stale needs a tenant context; run it through tenants:run.');

            return self::FAILURE;
        }

        $before = $this->option('before');
        $cutoff = is_string($before) && $before !== '' ? $before : Clock::today()->toDateString();

        $stale = SessionInstance::query()
            ->whereDate('session_date', '<', $cutoff)
            ->whereIn('status', SessionStatus::open())
            ->orderBy('session_date')->orderBy('id')
            ->get();

        $closed = 0;
        $failed = 0;

        foreach ($stale as $instance) {
            try {
                $close->handle($instance, Actor::system(), 'stale');
                $closed++;
            } catch (Throwable $e) {
                $failed++;
                report($e);
                $this->components->error("Session {$instance->public_id}: {$e->getMessage()}");
            }
        }

        $this->components->info("{$closed} stale session(s) closed".($failed > 0 ? ", {$failed} failed" : '').'.');

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
