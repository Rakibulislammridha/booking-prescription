<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Notifications;

use App\Domain\Notifications\Actions\RegisterPushSubscription;
use App\Domain\Notifications\Services\VapidSigner;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Notifications\SubscribeToPushRequest;
use App\Http\Resources\Notifications\PushSubscriptionResource;
use App\Models\Tenant\PushSubscription;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The service-worker subscription flow (BRIEF §5.J "push"):
 *   1. the client fetches the VAPID public key from `key`;
 *   2. it calls `registration.pushManager.subscribe({ applicationServerKey })`;
 *   3. it POSTs the resulting `{endpoint, keys}` here, and the row is bound to whoever is signed in — a staff user
 *      on the panel, a patient on the portal. There is no "subscribe on behalf of" path.
 */
final class PushSubscriptionController extends Controller
{
    public function key(VapidSigner $vapid): JsonResponse
    {
        return response()->json(['public_key' => $vapid->isConfigured() ? $vapid->publicKey() : null]);
    }

    public function store(SubscribeToPushRequest $request, RegisterPushSubscription $register): JsonResponse
    {
        $subscriber = $this->subscriber($request);

        if (! $subscriber instanceof Model) {
            return response()->json(['message' => __('notifications.errors.push_no_subscriber'), 'code' => 'notifications.push_no_subscriber'], 422);
        }

        /** @var array<string, string> $keys */
        $keys = (array) $request->input('keys', []);

        $subscription = $register->handle(
            $subscriber,
            (string) $request->input('endpoint'),
            $keys,
            $request->userAgent(),
            (string) $request->input('content_encoding', 'aes128gcm'),
        );

        return response()->json(['data' => (new PushSubscriptionResource($subscription))->resolve()], 201);
    }

    public function destroy(Request $request): JsonResponse
    {
        $endpoint = (string) $request->input('endpoint', '');
        $subscriber = $this->subscriber($request);

        if ($endpoint === '' || ! $subscriber instanceof Model) {
            return response()->json(['message' => __('notifications.errors.push_no_subscriber'), 'code' => 'notifications.push_no_subscriber'], 422);
        }

        PushSubscription::query()
            ->where('endpoint_hash', PushSubscription::hash($endpoint))
            ->where('subscriber_type', $subscriber->getMorphClass())
            ->where('subscriber_id', (int) $subscriber->getKey())
            ->get()
            ->each(fn (PushSubscription $row) => $row->delete());

        return response()->json(['deleted' => true]);
    }

    private function subscriber(Request $request): ?Model
    {
        $user = $request->user('web');

        if ($user instanceof Model) {
            return $user;
        }

        $patient = $request->user('patient');

        return $patient instanceof Model ? $patient : null;
    }
}
