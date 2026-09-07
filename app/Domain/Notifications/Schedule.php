<?php

declare(strict_types=1);

namespace App\Domain\Notifications;

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Console\Scheduling\Schedule as Scheduler;

/**
 * Discovered by routes/console.php (ARCHITECTURE §4.7). One command, four windows — SCHEMA decision 27 keeps the
 * list of scheduled commands closed at `notifications:send-reminders`, so the window is an option, not a command.
 *
 * The three reminder windows run hourly and gate themselves on the clinic-local hour, because `tenants:run` fans
 * out over clinics that may (later) sit in different timezones; the `due` window runs every minute so a follow-up
 * scheduled eleven weeks ago goes out on the minute it becomes due.
 */
final class Schedule implements RegistersSchedule
{
    public function register(Scheduler $schedule): void
    {
        $schedule->command('tenants:run notifications:send-reminders --option=window=day-before')->hourly()->timezone('Asia/Dhaka')->onOneServer();
        $schedule->command('tenants:run notifications:send-reminders --option=window=morning')->hourly()->timezone('Asia/Dhaka')->onOneServer();
        $schedule->command('tenants:run notifications:send-reminders --option=window=due')->everyMinute()->onOneServer()->withoutOverlapping();
    }
}
