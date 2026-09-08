<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Console\Scheduling\Schedule as Scheduler;

/** Discovered by routes/console.php (ARCHITECTURE §4.7). */
final class Schedule implements RegistersSchedule
{
    public function register(Scheduler $schedule): void
    {
        // Advance-payment holds are minutes-scale, so the sweep runs often enough that the released number is back
        // in the online pool while the session is still selling (BRIEF §5.C).
        $schedule->command('tenants:run booking:expire-holds')->everyFiveMinutes()->onOneServer()->withoutOverlapping();
    }
}
