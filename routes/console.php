<?php

declare(strict_types=1);

use App\Support\Scheduling\RegistersSchedule;
use Illuminate\Support\Facades\Schedule;

/*
 * Modules register their schedule in app/Domain/<Module>/Schedule.php (implements RegistersSchedule);
 * this file discovers and calls every one of them (ARCHITECTURE §4.7). Nobody edits this file for a schedule entry.
 */

// Foundation-owned entries (cross-module housekeeping): per-tenant fan-out through tenants:run.
Schedule::command('tenants:run patients:prune-otp')->dailyAt('03:30')->timezone('Asia/Dhaka')->onOneServer();

foreach (glob(app_path('Domain/*/Schedule.php')) ?: [] as $file) {
    $class = 'App\\Domain\\'.basename(dirname($file)).'\\Schedule';

    if (class_exists($class) && is_subclass_of($class, RegistersSchedule::class)) {
        app($class)->register(Schedule::getFacadeRoot());
    }
}
