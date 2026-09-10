<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\SuperAdmin;
use Illuminate\Auth\Events\Logout;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/** An operator who signs out drops out of their own device list at once, not when the index entry expires. */
final class ForgetSuperSession
{
    public function __construct(
        private readonly Application $app,
        private readonly SuperSessionIndex $sessions,
    ) {}

    public function handle(Logout $event): void
    {
        if ($event->guard !== 'super' || ! $event->user instanceof SuperAdmin || ! $this->app->bound('request')) {
            return;
        }

        /** @var Request $request */
        $request = $this->app->make('request');

        if ($request->hasSession()) {
            $this->sessions->forget($event->user, $request->session()->getId());
        }
    }
}
