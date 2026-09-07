<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Models\Tenant\NotificationLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One delivery attempt for the detail drawer. `request` was sanitised before it was stored (no credentials) and
 * `response` is the provider's own body, so both are safe to show to the admin who has to argue with the gateway.
 *
 * @mixin NotificationLog
 */
final class NotificationLogResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'attempt_no' => $this->attempt_no,
            'provider' => $this->provider,
            'provider_message_id' => $this->provider_message_id,
            'status' => $this->status->value,
            'request' => $this->request,
            'response' => $this->response,
            'error_code' => $this->error_code,
            'latency_ms' => $this->latency_ms,
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
