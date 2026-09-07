<?php

declare(strict_types=1);

namespace App\Http\Resources\Reception;

use App\Models\Tenant\ReceptionDevice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OFFLINE §2.1 device shape.
 *
 * @mixin ReceptionDevice
 */
final class ReceptionDeviceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'number' => $this->number,
            'name' => $this->name,
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'branch_id' => $this->whenLoaded('branch', fn () => $this->branch->public_id, null),
            'block_size' => $this->block_size,
            'app_version' => $this->app_version,
            'last_seen_at' => $this->last_seen_at?->toIso8601ZuluString(),
            'last_sync_at' => $this->last_sync_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'receipt_prefix' => $this->receiptPrefix(),
        ];
    }
}
