<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine;

use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Telemedicine\Events\CallEnded;
use App\Domain\Telemedicine\Events\TelemedicineInviteIssued;
use App\Domain\Telemedicine\Listeners\CancelRoomOnSerialEnded;
use App\Domain\Telemedicine\Listeners\MeterTelemedicineMinutes;
use App\Domain\Telemedicine\Listeners\ScheduleRoomOnAppointmentBooked;
use App\Domain\Telemedicine\Listeners\SendTelemedicineInvite;
use App\Domain\Telemedicine\Policies\TelemedicineRoomPolicy;
use App\Domain\Telemedicine\Services\RoomStateBuilder;
use App\Domain\Telemedicine\Services\TelemedicineSettings;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Models\Tenant\TelemedicineRoom;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The module's single provider (CONVENTIONS §12).
 *
 * `VideoProviderManager` and `TelemedicineSettings` are bound NON-shared on purpose: both memoise ONE clinic's
 * credentials, and under Octane a singleton would carry tenant A's LiveKit secret into tenant B's request. This
 * is the same reasoning that shapes Notifications' GatewayResolver.
 */
final class TelemedicineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(TelemedicineSettings::class);
        $this->app->bind(VideoProviderManager::class);
        $this->app->singleton(RoomStateBuilder::class);
    }

    public function boot(): void
    {
        Gate::policy(TelemedicineRoom::class, TelemedicineRoomPolicy::class);

        Event::listen(AppointmentBooked::class, ScheduleRoomOnAppointmentBooked::class);
        Event::listen(TelemedicineInviteIssued::class, SendTelemedicineInvite::class);
        Event::listen(CallEnded::class, MeterTelemedicineMinutes::class);
        Event::listen(SerialCancelled::class, CancelRoomOnSerialEnded::class);
        Event::listen(SerialNoShow::class, CancelRoomOnSerialEnded::class);

        // The waiting room polls the call document every few seconds on a phone that may reconnect often; the
        // token endpoint is the expensive one and is deliberately much tighter.
        RateLimiter::for('telemedicine-state', fn (Request $request) => Limit::perMinute(120)->by($this->key($request)));
        RateLimiter::for('telemedicine-token', fn (Request $request) => Limit::perMinute(20)->by($this->key($request)));
    }

    private function key(Request $request): string
    {
        $patient = $request->user('patient')?->getKey();
        $user = $request->user('web')?->getKey();

        return implode(':', [
            (string) (Tenancy::check() ? Tenancy::id() : 'central'),
            $patient !== null ? 'p'.$patient : ($user !== null ? 'u'.$user : (string) $request->ip()),
        ]);
    }
}
