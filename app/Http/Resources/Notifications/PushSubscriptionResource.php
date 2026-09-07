<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Models\Tenant\PushSubscription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The endpoint is a bearer-like URL: only its host and a short digest are exposed.
 *
 * @mixin PushSubscription
 */
final class PushSubscriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'subscriber_type' => class_basename($this->subscriber_type),
            'subscriber_id' => $this->subscriber_id,
            'endpoint_host' => (string) parse_url($this->endpoint, PHP_URL_HOST),
            'endpoint_digest' => substr($this->endpoint_hash, 0, 12),
            'content_encoding' => $this->content_encoding,
            'user_agent' => $this->user_agent,
            'failed_count' => $this->failed_count,
            'last_used_at' => $this->last_used_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
