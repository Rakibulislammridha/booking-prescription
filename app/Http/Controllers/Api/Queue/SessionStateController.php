<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Queue;

use App\Domain\Queue\Services\QueueStateRepository;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Queue\QueueStateController;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * `GET /api/queue/sessions/{session}/state` (`api.queue.sessions.state`) — the same QueueState document and the same
 * ETag contract as the public `site.queue.state`, addressed by session public id. Used by the doctor screen and by
 * the waiting-room display, which know session ids but not always a doctor slug (REALTIME.md §4.1, §9.1).
 *
 * No authorize() here, deliberately — not an oversight, and not a place to add one without changing both endpoints:
 * byte for byte this is what `site.queue.state` (LOCKED, REALTIME.md §5.2) hands any anonymous visitor who knows the
 * doctor's public slug, and QueueStateBuilder puts codes, numbers, positions, statuses and ETAs in it and never a
 * patient identifier (REALTIME.md §12). Authenticating the caller buys addressing by session id, nothing more. A
 * policy check could not even be expressed: the route is `auth:sanctum,device`, and the display TV authenticates as
 * a ReceptionDevice, which is not an Authorizable and would fail every Gate. If this document ever grows a field
 * the public endpoint does not serve, that is the moment this route needs a rule of its own — and so does the
 * public one.
 */
final class SessionStateController extends Controller
{
    public function __invoke(Request $request, SessionInstance $session, QueueStateRepository $repository): Response
    {
        $version = $repository->version($session);

        if ($version !== null && QueueStateController::matchesVersion($request->headers->get('If-None-Match'), $version)) {
            return new Response('', 304, [
                'ETag' => '"'.$version.'"',
                'Cache-Control' => 'no-cache, private',
                'X-Queue-Session' => $session->public_id,
            ]);
        }

        $json = $repository->snapshot($session) ?? json_encode($repository->rebuild($session), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $decoded = json_decode($json, false);
        $version = is_object($decoded) && is_numeric($decoded->version ?? null) ? (int) $decoded->version : $session->version;

        return new Response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => '"'.$version.'"',
            'Cache-Control' => 'no-cache, private',
            'X-Queue-Session' => $session->public_id,
        ]);
    }
}
