<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A household member as seen from another member: summary + relation to the owner.
 *
 * @mixin Patient
 */
final class FamilyMemberResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $relation = $this->pivot?->getAttribute('relation') ?? $this->primaryRelation?->relation;

        return [
            ...(new PatientSummaryResource($this->resource))->toArray($request),
            'relation' => $relation instanceof \BackedEnum ? $relation->value : ($relation === null ? null : (string) $relation),
        ];
    }
}
