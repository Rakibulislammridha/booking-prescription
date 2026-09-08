<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Listeners;

use App\Http\Middleware\EnforceIdleTimeout;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Events\Login;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;

/**
 * Starts the idle clock and remembers WHEN this login happened, so the first request after it is measured from the
 * login rather than from nothing.
 *
 * It deliberately does not write the `sessions_by_user` entry itself: `Auth::attempt()` fires Login and the login
 * controller regenerates the session id immediately afterwards, so an id captured here would be destroyed a
 * microsecond later. `EnforceIdleTimeout` creates the entry on the first authenticated request, by which time the
 * id is the one the browser will actually present — and that also covers the remember-me recaller and any other
 * path into an authenticated session.
 */
final class RecordStaffSession
{
    public const LOGIN_AT_KEY = 'staff_session_login_at';

    public function __construct(private readonly Application $app) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User || ! $this->app->bound('request')) {
            return;
        }

        /** @var Request $request */
        $request = $this->app->make('request');

        if (! $request->hasSession()) {
            return;
        }

        $now = CarbonImmutable::now()->getTimestamp();
        $request->session()->put(EnforceIdleTimeout::SESSION_KEY, $now);
        $request->session()->put(self::LOGIN_AT_KEY, $now);
    }
}
