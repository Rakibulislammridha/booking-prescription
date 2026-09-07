<?php

declare(strict_types=1);

namespace App\Support\Scheduling;

use Illuminate\Console\Scheduling\Schedule;

/**
 * Implemented by app/Domain/<Module>/Schedule.php; routes/console.php discovers and calls every implementation,
 * so no module edits routes/console.php (ARCHITECTURE §4.7).
 */
interface RegistersSchedule
{
    public function register(Schedule $schedule): void;
}
