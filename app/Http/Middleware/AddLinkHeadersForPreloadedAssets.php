<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Adds Link: <...>; rel=preload headers for the Vite assets the response rendered (HTTP/2 push hint).
 */
final class AddLinkHeadersForPreloadedAssets
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response instanceof Response || $request->header('X-Inertia')) {
            return $response;
        }

        $links = collect(Vite::preloadedAssets())
            ->map(fn ($attributes, $url) => "<{$url}>; ".implode('; ', is_array($attributes) ? $attributes : []))
            ->values()
            ->all();

        if ($links !== []) {
            $response->headers->set('Link', implode(', ', $links), false);
        }

        return $response;
    }
}
