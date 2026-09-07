<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Console\Scheduling\Schedule as Scheduler;

/** catalog:reconcile nightly at 02:00 (ARCHITECTURE.md §4.7; tenants:backup owns 02:30). */
final class Schedule implements RegistersSchedule
{
    public function register(Scheduler $schedule): void
    {
        $schedule->command('catalog:reconcile')->dailyAt('02:00')->timezone('Asia/Dhaka')->onOneServer()->withoutOverlapping();
    }
}
