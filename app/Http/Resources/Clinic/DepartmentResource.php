<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\Department;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Department */
final class DepartmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'branch_id' => $this->branch_id, 'name' => $this->name, 'name_bn' => $this->name_bn, 'slug' => $this->slug, 'sort_order' => $this->sort_order, 'is_active' => $this->is_active];
    }
}
