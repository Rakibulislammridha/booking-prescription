<?php

declare(strict_types=1);

namespace App\Domain\Prescription;

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Console\Scheduling\Schedule as Scheduler;

/** Discovered by routes/console.php (ARCHITECTURE §4.7). */
final class Schedule implements RegistersSchedule
{
    public function register(Scheduler $schedule): void
    {
        $schedule->command('tenants:run prescriptions:recompute-favourites')->dailyAt('01:30')->timezone('Asia/Dhaka')->onOneServer()->withoutOverlapping();   // PRESCRIPTION.md §3.5
    }
}
