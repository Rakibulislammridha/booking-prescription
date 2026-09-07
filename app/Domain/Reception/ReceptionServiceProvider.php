<?php

declare(strict_types=1);

namespace App\Domain\Reception;

use App\Domain\Reception\Console\SyncHammerCommand;
use App\Domain\Reception\Contracts\CashCollector;
use App\Domain\Reception\Listeners\ReleaseBlocksOnSessionClosed;
use App\Domain\Reception\Policies\ReceptionDevicePolicy;
use App\Domain\Reception\Services\NullCashCollector;
use App\Domain\Serials\Events\SessionClosed;
use App\Models\Tenant\ReceptionDevice;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/** Reception module wiring: the CashCollector binding Billing rebinds, the session-closed block safety net, the sync hammer. */
final class ReceptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(CashCollector::class, NullCashCollector::class);
    }

    public function boot(): void
    {
        Gate::policy(ReceptionDevice::class, ReceptionDevicePolicy::class);
        Event::listen(SessionClosed::class, ReleaseBlocksOnSessionClosed::class);

        if ($this->app->runningInConsole()) {
            $this->commands([SyncHammerCommand::class]);
        }
    }
}
