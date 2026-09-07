<?php

declare(strict_types=1);

namespace App\Domain\Serials;

use App\Domain\Serials\Console\HammerCommand;
use App\Domain\Serials\Policies\SerialPolicy;
use App\Domain\Serials\Policies\SessionInstancePolicy;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

final class SerialsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // config/* is foundation-owned, so the one flag this module needs is seeded here (default off). Only the test
        // suite / serials:hammer --skip-owner-lock flip it with config([...]), and AllocateSerial::lockOwnerRow()
        // honours it only when app()->environment('testing') (CONVENTIONS §6.5).
        if (! $this->app['config']->has('serials.testing_skip_owner_lock')) {
            $this->app['config']->set('serials.testing_skip_owner_lock', false);
        }

        $this->app->singleton(SerialEventWriter::class);
        $this->app->singleton(CapacityService::class);
    }

    public function boot(): void
    {
        Gate::policy(SessionInstance::class, SessionInstancePolicy::class);
        Gate::policy(Serial::class, SerialPolicy::class);

        if ($this->app->runningInConsole()) {
            $this->commands([HammerCommand::class]);
        }
    }
}
