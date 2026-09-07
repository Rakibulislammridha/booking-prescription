<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Models\Tenant\NotificationTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin NotificationTemplate */
final class NotificationTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'event_key' => $this->event_key->value,
            'channel' => $this->channel->value,
            'locale' => $this->locale->value,
            'subject' => $this->subject,
            'body' => $this->body,
            'provider_template_id' => $this->provider_template_id,
            'is_active' => $this->is_active,
            'is_default' => false,
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
