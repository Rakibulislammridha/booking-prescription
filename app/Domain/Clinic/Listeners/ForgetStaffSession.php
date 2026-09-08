<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Listeners;

use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Models\Tenant\User;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/** A staff member who logs out drops out of the device list immediately, not when the index entry expires. */
final class ForgetStaffSession
{
    public function __construct(
        private readonly Application $app,
        private readonly StaffSessionIndex $sessions,
    ) {}

    public function handle(Logout $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User || ! $this->app->bound('request')) {
            return;
        }

        /** @var Request $request */
        $request = $this->app->make('request');

        if ($request->hasSession()) {
            $this->sessions->forget($event->user, $request->session()->getId());
        }
    }
}
