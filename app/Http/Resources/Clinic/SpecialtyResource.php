<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\Specialty;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Specialty */
final class SpecialtyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'name_bn' => $this->name_bn, 'slug' => $this->slug, 'icon' => $this->icon, 'sort_order' => $this->sort_order, 'is_active' => $this->is_active, 'doctors_count' => $this->whenCounted('doctorSpecialties')];
    }
}
