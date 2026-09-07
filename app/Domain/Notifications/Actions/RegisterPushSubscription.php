<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Models\Tenant\PushSubscription;
use Illuminate\Database\Eloquent\Model;

/**
 * The service-worker subscription flow's server half: the browser hands over `{endpoint, keys:{p256dh, auth}}` and
 * this stores it against the signed-in staff user or patient. Idempotent on `endpoint_hash`, because a browser
 * re-subscribes on every service-worker update and would otherwise leave a trail of dead rows.
 */
final class RegisterPushSubscription
{
    /** @param  array<string, string>  $keys */
    public function handle(Model $subscriber, string $endpoint, array $keys, ?string $userAgent, string $contentEncoding = 'aes128gcm'): PushSubscription
    {
        $subscription = PushSubscription::query()->firstOrNew(['endpoint_hash' => PushSubscription::hash($endpoint)]);

        $subscription->fill([
            'subscriber_type' => $subscriber->getMorphClass(),
            'subscriber_id' => (int) $subscriber->getKey(),
            'endpoint' => $endpoint,
            'endpoint_hash' => PushSubscription::hash($endpoint),
            'keys' => ['p256dh' => $keys['p256dh'] ?? '', 'auth' => $keys['auth'] ?? ''],
            'content_encoding' => $contentEncoding,
            'user_agent' => $userAgent === null ? null : mb_substr($userAgent, 0, 500),
            'failed_count' => 0,
        ])->save();

        return $subscription;
    }
}
