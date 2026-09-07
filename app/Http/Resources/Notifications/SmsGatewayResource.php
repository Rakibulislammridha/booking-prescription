<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Models\Tenant\SmsGatewaySetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A gateway row WITHOUT its credentials — only which credential keys are present, so the form can show
 * "API token: set" and let an admin replace it without ever reading it back.
 *
 * @mixin SmsGatewaySetting
 */
final class SmsGatewayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'channel' => $this->channel->value,
            'provider' => $this->provider->value,
            'name' => $this->name,
            'sender_id' => $this->sender_id,
            'credential_keys' => array_keys(array_filter($this->credentials, fn ($v) => is_scalar($v) && (string) $v !== '')),
            'options' => $this->options,
            'priority' => $this->priority,
            'is_default' => $this->is_default,
            'is_active' => $this->is_active,
            'balance_paisa' => $this->balance_paisa,
            'balance_checked_at' => $this->balance_checked_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
