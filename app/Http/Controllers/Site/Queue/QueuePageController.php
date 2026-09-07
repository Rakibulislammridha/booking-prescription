<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Queue;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\Services\SessionResolver;
use App\Domain\Queue\TenantChannel;
use App\Http\Controllers\Controller;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The public queue page — `GET /q/{doctorSlug}/today` (`site.queue.page`) and the shorter
 * `queue.{host}/{doctorSlug}/today` (`site.queue.vanity`), BRIEF §5.E: no login, no PII, one Redis read.
 *
 * The initial QueueState is embedded in the Inertia props so the first paint needs no XHR (REALTIME.md §8); the
 * client then keeps it live through subscribeQueue() — WebSocket first, 5 s ETag poll when degraded.
 */
final class QueuePageController extends Controller
{
    public function __invoke(Request $request, SessionResolver $resolver, QueueStateRepository $repository, Settings $settings): Response
    {
        if ($settings->get('queue.public_page_enabled') === false) {
            throw new NotFoundHttpException('queue.public_page_disabled');
        }

        // The vanity host route carries a `{tenantHost}` domain parameter, which would be spliced into a positional
        // `string $doctorSlug` argument; the slug is therefore read from the route by name (REALTIME.md §5.1).
        $doctor = $resolver->doctor((string) $request->route('doctorSlug'));
        $sessions = $resolver->today($doctor);
        $session = $resolver->forDoctor($doctor, QueueStateController::pinned($request));
        $tenant = Tenancy::current();

        return Inertia::render('Queue/Today', [
            'tenant_public_id' => $tenant?->public_id,
            'channel' => $session === null || $tenant === null ? null : TenantChannel::queueName($tenant->public_id, $session->public_id),
            'doctor' => [
                'public_id' => $doctor->public_id, 'slug' => $doctor->slug, 'name' => $doctor->name,
                'name_bn' => $doctor->name_bn, 'room' => $doctor->room_label,
            ],
            'date' => Clock::today()->toDateString(),
            'session_id' => $session?->public_id,
            'state' => $session === null ? null : $repository->state($session),
            'sessions' => $sessions->map(fn (SessionInstance $s) => QueueSessionsController::row($s))->values()->all(),
            'serial' => self::pinnedSerial($request),
            'notify_ahead' => (int) $settings->get('queue.notify_ahead'),
        ]);
    }

    /** `?s=` is the documented parameter (REALTIME.md §5.3); `?serial=` is accepted as an alias for hand-typed links. */
    public static function pinnedSerial(Request $request): ?string
    {
        foreach (['s', 'serial'] as $key) {
            $value = $request->query($key);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return null;
    }
}
