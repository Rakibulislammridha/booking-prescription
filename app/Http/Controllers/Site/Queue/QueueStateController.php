<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Queue;

use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Queue\Services\SessionResolver;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * LOCKED path `GET /queue/{doctorSlug}/state?session=` (REALTIME.md §5.2, `site.queue.state`, public).
 *
 * The ETag **is** the quoted integer `session_instances.version`. `If-None-Match` hit ⇒ 304 at the cost of one Redis
 * GET and no JSON work; a miss passes the stored JSON string through without decoding anything but its version.
 */
final class QueueStateController extends Controller
{
    public function __invoke(Request $request, string $doctorSlug, QueueStateRepository $repository, SessionResolver $resolver): Response
    {
        $session = $resolver->resolve($doctorSlug, self::pinned($request));
        $version = $repository->version($session);

        if ($version !== null && self::matchesVersion($request->headers->get('If-None-Match'), $version)) {
            return new Response('', 304, [
                'ETag' => '"'.$version.'"',
                'Cache-Control' => 'no-cache, private',
                'X-Queue-Session' => $session->public_id,
            ]);
        }

        $json = $repository->snapshot($session);

        if ($json === null) {
            $json = json_encode($repository->rebuild($session), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        }

        $decoded = json_decode($json, false);
        $version = is_object($decoded) && is_numeric($decoded->version ?? null) ? (int) $decoded->version : $session->version;

        return new Response($json, 200, [
            'Content-Type' => 'application/json; charset=utf-8',
            'ETag' => '"'.$version.'"',
            'Cache-Control' => 'no-cache, private',
            'Vary' => 'Accept-Encoding',
            'X-Queue-Session' => $session->public_id,
        ]);
    }

    /**
     * `If-None-Match` against the version. A compressing reverse proxy rewrites strong ETags per encoding
     * (Caddy/FrankenPHP turns `"14"` into `"14-zstd"`), and a client may send a weak or comma-separated list, so the
     * entity-tag is parsed instead of compared verbatim — the version is still the only thing that decides.
     */
    public static function matchesVersion(?string $header, int $version): bool
    {
        if ($header === null || $header === '') {
            return false;
        }

        foreach (explode(',', $header) as $candidate) {
            $tag = trim($candidate);
            $tag = preg_replace('/^W\//i', '', $tag) ?? $tag;
            $tag = trim($tag, '"');

            if ($tag === (string) $version || str_starts_with($tag, $version.'-')) {
                return true;
            }
        }

        return false;
    }

    public static function pinned(Request $request): ?string
    {
        $value = $request->query('session');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
