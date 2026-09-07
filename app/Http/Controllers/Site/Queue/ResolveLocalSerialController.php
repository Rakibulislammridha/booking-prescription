<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Queue;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Serial;
use Illuminate\Http\JsonResponse;

/**
 * `GET /q/resolve/{localId}` (`site.queue.resolve`, OFFLINE.md §10): the QR on a slip printed offline carries the
 * device's `client_event_id`; once the desk syncs, the serial exists and this maps it to the public ids the page
 * needs. Until then the page shows "registered at the desk, live position will appear when the desk reconnects".
 */
final class ResolveLocalSerialController extends Controller
{
    public function __invoke(string $localId): JsonResponse
    {
        $clientEventId = preg_replace('/^local:/i', '', trim($localId)) ?? '';

        $serial = Serial::query()->with('sessionInstance.doctor')
            ->where('client_event_id', $clientEventId)
            ->first();

        if ($serial === null) {
            return response()->json(['resolved' => false, 'local_id' => $localId], 200)->header('Cache-Control', 'no-store');
        }

        $session = $serial->sessionInstance;

        return response()->json([
            'resolved' => true,
            'local_id' => $localId,
            'serial' => ['public_id' => $serial->public_id, 'display_code' => $serial->display_code, 'number' => $serial->number, 'status' => $serial->status->value],
            'session' => ['public_id' => $session->public_id, 'code' => $session->session_code, 'date' => $session->session_date->toDateString()],
            'doctor' => ['slug' => $session->doctor->slug, 'name' => $session->doctor->name, 'name_bn' => $session->doctor->name_bn],
        ])->header('Cache-Control', 'no-store');
    }
}
