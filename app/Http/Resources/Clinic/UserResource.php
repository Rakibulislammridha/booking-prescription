<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
final class UserResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'mobile' => $this->mobile,
            'default_branch_id' => $this->default_branch_id,
            'locale' => $this->locale,
            'is_active' => $this->is_active,
            'roles' => $this->getRoleNames()->values()->all(),
            'role' => $this->getRoleNames()->first(),
            'must_change_password' => $this->must_change_password,
            'session_timeout_minutes' => $this->session_timeout_minutes,
            'doctor_id' => $this->whenLoaded('doctor', fn () => $this->doctor?->id),
            'branch_name' => $this->whenLoaded('defaultBranch', fn () => $this->defaultBranch?->name),
            'last_login_at' => $this->last_login_at?->toIso8601ZuluString(),
        ];
    }
}
