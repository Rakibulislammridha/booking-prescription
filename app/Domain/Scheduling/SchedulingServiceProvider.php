<?php

declare(strict_types=1);

namespace App\Domain\Scheduling;

use App\Domain\Clinic\Events\DoctorLeaveCreated;
use App\Domain\Scheduling\Console\CloseStaleSessionsCommand;
use App\Domain\Scheduling\Console\MaterialiseSessionsCommand;
use App\Domain\Scheduling\Listeners\CancelSessionsForLeave;
use App\Domain\Scheduling\Policies\DoctorSchedulePolicy;
use App\Domain\Scheduling\Policies\ScheduleOverridePolicy;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\ScheduleOverride;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class SchedulingServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::policy(DoctorSchedule::class, DoctorSchedulePolicy::class);
        Gate::policy(ScheduleOverride::class, ScheduleOverridePolicy::class);

        Event::listen(DoctorLeaveCreated::class, CancelSessionsForLeave::class);

        if ($this->app->runningInConsole()) {
            $this->commands([MaterialiseSessionsCommand::class, CloseStaleSessionsCommand::class]);
        }
    }
}
