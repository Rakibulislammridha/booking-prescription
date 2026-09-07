<?php

declare(strict_types=1);

namespace App\Domain\Queue;

use App\Domain\Queue\Console\HammerCommand;
use App\Domain\Queue\Console\RefreshEtaCommand;
use App\Domain\Queue\Listeners\BroadcastSerialCalled;
use App\Domain\Queue\Listeners\BroadcastSerialStatusChanged;
use App\Domain\Queue\Listeners\BroadcastSessionEvent;
use App\Domain\Queue\Listeners\InvalidateQueueState;
use App\Domain\Queue\Listeners\NotifyApproachingSerials;
use App\Domain\Queue\Services\BoardStateBuilder;
use App\Domain\Queue\Services\EtaCalculator;
use App\Domain\Queue\Services\QueueBroadcaster;
use App\Domain\Queue\Services\QueueStateBuilder;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\Services\SessionResolver;
use App\Domain\Serials\Events\DoctorArrived;
use App\Domain\Serials\Events\SerialAllocated;
use App\Domain\Serials\Events\SerialCalled;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialCompleted;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Serials\Events\SerialPostponed;
use App\Domain\Serials\Events\SerialPriorityInserted;
use App\Domain\Serials\Events\SerialReinstated;
use App\Domain\Serials\Events\SerialReinstatedAfterCancel;
use App\Domain\Serials\Events\SerialReordered;
use App\Domain\Serials\Events\SerialStatusChanged;
use App\Domain\Serials\Events\SerialTransferred;
use App\Domain\Serials\Events\SessionCancelled;
use App\Domain\Serials\Events\SessionCapacityExtended;
use App\Domain\Serials\Events\SessionClosed;
use App\Domain\Serials\Events\SessionDelayed;
use App\Domain\Serials\Events\SessionPaused;
use App\Domain\Serials\Events\SessionResumed;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The live queue (REALTIME.md). Owns the QueueState store, the wire events and their listeners on the Serials domain
 * events, the four broadcast channels and their guards, the `queue-state` limiter and `queue:refresh-eta`.
 */
final class QueueServiceProvider extends ServiceProvider
{
    /**
     * Every Serials domain event that changes what a waiting patient sees (REALTIME.md §4.3). Order matters:
     * InvalidateQueueState is registered first for each, so a broadcast listener always reads the fresh version.
     *
     * @var array<int, class-string>
     */
    public const INVALIDATING_EVENTS = [
        SerialAllocated::class,
        SerialStatusChanged::class,
        SerialCalled::class,
        SerialCompleted::class,
        SerialCancelled::class,
        SerialNoShow::class,
        SerialReinstated::class,
        SerialReinstatedAfterCancel::class,
        SerialPostponed::class,
        SerialTransferred::class,
        SerialReordered::class,
        SerialPriorityInserted::class,
        SessionCapacityExtended::class,
        SessionDelayed::class,
        SessionCancelled::class,
        SessionClosed::class,
        DoctorArrived::class,
        SessionPaused::class,
        SessionResumed::class,
    ];

    public function register(): void
    {
        $this->app->singleton(EtaCalculator::class);
        $this->app->singleton(QueueStateBuilder::class);
        $this->app->singleton(QueueStateRepository::class);
        $this->app->singleton(BoardStateBuilder::class);
        $this->app->singleton(QueueBroadcaster::class);
        $this->app->singleton(SessionResolver::class);
    }

    public function boot(): void
    {
        $this->registerListeners();
        self::registerChannels();

        // REALTIME.md §5.1: 30 requests/min per IP + session — a 5 s poll uses 12, a 30 s hidden-tab poll uses 2.
        RateLimiter::for('queue-state', fn (Request $request) => Limit::perMinute(30)
            ->by('queue-state:'.$request->ip().':'.((string) $request->query('session', '')))
        );

        if ($this->app->runningInConsole()) {
            $this->commands([HammerCommand::class, RefreshEtaCommand::class]);
        }
    }

    private function registerListeners(): void
    {
        foreach (self::INVALIDATING_EVENTS as $event) {
            Event::listen($event, InvalidateQueueState::class);
        }

        Event::listen(SerialCalled::class, BroadcastSerialCalled::class);
        Event::listen(SerialCalled::class, NotifyApproachingSerials::class);
        Event::listen(SerialStatusChanged::class, BroadcastSerialStatusChanged::class);

        foreach ([SessionDelayed::class, SessionCancelled::class, DoctorArrived::class] as $event) {
            Event::listen($event, BroadcastSessionEvent::class);
        }
    }

    /**
     * REALTIME.md §2. `routes/channels.php` is foundation-owned and lists these four lines as the inventory; they are
     * registered here so the Queue module owns its guards. `Broadcast::channel()` cannot take a `[Class, 'method']`
     * array (Broadcaster::extractParameters reflects a Closure or a class-string only), hence the thin closures.
     *
     * Public and static because `Broadcast::channel()` registers on the CURRENT default broadcaster: anything that
     * swaps `broadcasting.default` after boot (the channel-auth tests) must re-register on the new driver.
     */
    public static function registerChannels(): void
    {
        $guards = app(ChannelGuards::class);

        Broadcast::channel(
            'tenant.{tenant}.reception.{branch}',
            fn (?Authenticatable $auth, string $tenant, string $branch) => $guards->reception($auth, $tenant, $branch),
            ['guards' => ['web', 'sanctum', 'device']],
        );

        Broadcast::channel(
            'tenant.{tenant}.doctor.{doctor}',
            fn (?Authenticatable $auth, string $tenant, string $doctor) => $guards->doctor($auth, $tenant, $doctor),
            ['guards' => ['web', 'sanctum']],
        );

        Broadcast::channel(
            'tenant.{tenant}.display.{branch}',
            fn (?Authenticatable $auth, string $tenant, string $branch) => $guards->display($auth, $tenant, $branch),
            ['guards' => ['device', 'web']],
        );
    }
}
