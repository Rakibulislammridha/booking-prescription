<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Telemedicine;

use App\Domain\Telemedicine\Actions\HandleProviderWebhook;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /api/webhooks/telemedicine` — provider callbacks (CSRF-exempt by the `api/webhooks/*` rule in
 * bootstrap/app.php, and authenticated by the DRIVER's own signature check, never by a session).
 *
 * An unverifiable payload is 401 so a misconfigured secret is loud. A verified event for a room we no longer
 * have is 202: the provider has nothing to retry, and retrying forever after a tenant deleted an appointment is
 * a self-inflicted outage.
 */
final class WebhookController extends Controller
{
    public function __invoke(Request $request, HandleProviderWebhook $handle): JsonResponse
    {
        $headers = [];

        foreach ($request->headers->keys() as $key) {
            $headers[strtolower($key)] = (string) $request->headers->get($key);
        }

        $applied = $handle->handle($request->getContent(), $headers);

        if ($applied === null) {
            return response()->json(['message' => 'unverified', 'code' => 'telemedicine.webhook_unverified'], 401);
        }

        return response()->json(['applied' => $applied], $applied ? 200 : 202);
    }
}
