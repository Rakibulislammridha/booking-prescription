<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Drivers\Push;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Services\VapidSigner;
use App\Domain\Notifications\Services\WebPushEncryptor;
use App\Domain\Notifications\Support\Ec;
use App\Models\Tenant\PushSubscription;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Web Push (RFC 8030 + 8291 + 8292). The recipient of a push notification is the subscription's endpoint URL, so
 * `notifications.recipient` holds the endpoint and the encryption keys are read from `push_subscriptions` by
 * `endpoint_hash` — the ciphertext is bound to one subscription and cannot be re-sent to another.
 *
 * A push service answers 404/410 when the subscription is dead; that is permanent, and the row is pruned so the
 * clinic's list does not fill with browsers that were uninstalled months ago.
 */
final class WebPushDriver implements ChannelDriver
{
    public function __construct(
        private readonly VapidSigner $vapid,
        private readonly WebPushEncryptor $encryptor,
        private readonly int $timeout = 10,
        private readonly int $ttlSeconds = 3600,
        private readonly int $pruneAfterFailures = 5,
    ) {}

    public function channel(): NotificationChannel
    {
        return NotificationChannel::Push;
    }

    public function provider(): string
    {
        return 'webpush';
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        if (! $this->vapid->isConfigured()) {
            return DeliveryResult::rejected('vapid_not_configured');
        }

        $subscription = PushSubscription::query()->where('endpoint_hash', PushSubscription::hash($message->recipient))->first();

        if ($subscription === null) {
            return DeliveryResult::rejected('push_subscription_missing');
        }

        $payload = (string) json_encode(array_filter([
            'title' => $message->subject ?? $message->event->value,
            'body' => $message->body,
            'url' => is_string($message->payload['link'] ?? null) ? $message->payload['link'] : null,
            'tag' => $message->event->value,
        ], fn ($v) => $v !== null), JSON_UNESCAPED_UNICODE);

        $started = microtime(true);
        $request = ['endpoint_host' => (string) parse_url($message->recipient, PHP_URL_HOST), 'payload_bytes' => strlen($payload)];

        try {
            $keys = $subscription->keys;
            $body = $this->encryptor->encrypt(
                $payload,
                Ec::base64UrlDecode((string) ($keys['p256dh'] ?? '')),
                Ec::base64UrlDecode((string) ($keys['auth'] ?? '')),
            );

            $response = Http::withHeaders([
                ...$this->vapid->headers($message->recipient),
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => (string) $this->ttlSeconds,
                'Urgency' => $message->event->isUrgent() ? 'high' : 'normal',
            ])->timeout($this->timeout)->withBody($body, 'application/octet-stream')->post($message->recipient);
        } catch (ConnectionException $e) {
            $this->recordFailure($subscription);

            return DeliveryResult::failed('connection', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        } catch (Throwable $e) {
            return DeliveryResult::rejected('encryption_failed', ['message' => $e->getMessage()], $this->elapsed($started))->withRequest($request);
        }

        $latency = $this->elapsed($started);

        if ($response->successful()) {
            $subscription->forceFill(['last_used_at' => now(), 'failed_count' => 0])->save();

            return DeliveryResult::sent($response->header('Location') ?: null, ['status' => $response->status()], $latency)->withRequest($request);
        }

        if (in_array($response->status(), [404, 410], true)) {
            $subscription->delete();      // RFC 8030 §7.3: the subscription is gone for good.

            return DeliveryResult::rejected('subscription_expired', ['status' => $response->status()], $latency)->withRequest($request);
        }

        if ($response->status() === 413) {
            return DeliveryResult::rejected('payload_too_large', ['status' => $response->status()], $latency)->withRequest($request);
        }

        $this->recordFailure($subscription);

        return $response->serverError() || $response->status() === 429
            ? DeliveryResult::failed('http_'.$response->status(), ['status' => $response->status()], $latency)->withRequest($request)
            : DeliveryResult::rejected('http_'.$response->status(), ['status' => $response->status(), 'body' => mb_substr($response->body(), 0, 300)], $latency)->withRequest($request);
    }

    private function recordFailure(PushSubscription $subscription): void
    {
        $subscription->forceFill(['failed_count' => $subscription->failed_count + 1])->save();

        if ($subscription->failed_count >= $this->pruneAfterFailures) {
            $subscription->delete();
        }
    }

    private function elapsed(float $started): int
    {
        return (int) round((microtime(true) - $started) * 1000);
    }
}
