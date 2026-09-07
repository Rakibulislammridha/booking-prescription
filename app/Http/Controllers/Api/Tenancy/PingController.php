<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Tenancy;

use App\Http\Controllers\Controller;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\JsonResponse;

/**
 * GET /api/ping — the connection-store heartbeat (OFFLINE.md §3.1). No auth, never cached.
 */
final class PingController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()
            ->json(['t' => time(), 'tenant' => Tenancy::current()?->public_id])
            ->header('Cache-Control', 'no-store');
    }
}
