<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of a doctor's compounder list: the staff account, plus the assignment itself.
 *
 * `doctor_compounder` has no model, so neither the assignment timestamp nor the assigner's name can be a relation
 * the resource walks; the controller reads them off the pivot once and hands them in. `assignedBy` is null when
 * that account has since been deleted (the FK is ON DELETE SET NULL) — the row outlives its author, which is the
 * whole point of recording one.
 *
 * @mixin User
 */
final class DoctorCompounderResource extends JsonResource
{
    public function __construct(mixed $resource, private readonly ?string $assignedAt = null, private readonly ?string $assignedBy = null)
    {
        parent::__construct($resource);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'is_active' => $this->is_active,
            'assigned_at' => $this->assignedAt,
            'assigned_by' => $this->assignedBy,
        ];
    }
}
