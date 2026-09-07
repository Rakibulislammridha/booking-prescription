<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Console\Scheduling\Schedule as Scheduler;

/** Discovered by routes/console.php (ARCHITECTURE §4.7). */
final class Schedule implements RegistersSchedule
{
    public function register(Scheduler $schedule): void
    {
        $schedule->command('sessions:materialise --days=14')->dailyAt('00:10')->timezone('Asia/Dhaka');     // SERIAL_ENGINE §2.2
        $schedule->command('tenants:run sessions:close-stale')->dailyAt('23:55')->timezone('Asia/Dhaka');  // SERIAL_ENGINE §5.1
    }
}
