<?php

declare(strict_types=1);

namespace App\Http\Resources\Serials;

use App\Models\Tenant\SerialBlock;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * OFFLINE §4.1 block shape.
 *
 * @mixin SerialBlock
 */
final class SerialBlockResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'range_start' => $this->range_start,
            'range_end' => $this->range_end,
            'next_number' => $this->next_number,
            'status' => $this->status->value,
            'reception_device_id' => $this->reception_device_id,
            'leased_at' => $this->leased_at->toIso8601ZuluString(),
            'expires_at' => $this->expires_at?->toIso8601ZuluString(),
            'released_at' => $this->released_at?->toIso8601ZuluString(),
            'revoked_at' => $this->revoked_at?->toIso8601ZuluString(),
            'returned_count' => $this->returned_count,
        ];
    }
}
