<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Console\Scheduling\Schedule as Scheduler;

/** Discovered by routes/console.php (ARCHITECTURE §4.7). */
final class Schedule implements RegistersSchedule
{
    public function register(Scheduler $schedule): void
    {
        $schedule->command('tenants:run queue:refresh-eta')->everyMinute()->onOneServer()->withoutOverlapping();   // REALTIME.md §4.3
    }
}
