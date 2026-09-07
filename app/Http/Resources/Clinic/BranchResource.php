<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\Branch;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Branch */
final class BranchResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'is_main' => $this->is_main,
            'is_active' => $this->is_active,
            'geo' => $this->geo,
            'settings' => $this->settings,
        ];
    }
}
