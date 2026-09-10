<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Actions\Admins\DeleteSuperAdmin;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\SuperAdmin;

/** The Admins screen's read model: every operator with the facts the list and the edit page show. */
final class SuperAdminDirectory
{
    public function __construct(private readonly SuperTwoFactor $twoFactor) {}

    /** @return array<int, array<string, mixed>> active first, then by name */
    public function all(SuperAdmin $viewer): array
    {
        return SuperAdmin::query()
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get()
            ->map(fn (SuperAdmin $admin) => $this->row($admin, $viewer))
            ->all();
    }

    /** @return array<string, mixed> */
    public function row(SuperAdmin $admin, SuperAdmin $viewer): array
    {
        return [
            'id' => $admin->id,
            'name' => $admin->name,
            'email' => $admin->email,
            'is_active' => $admin->is_active,
            'two_factor' => $this->twoFactor->enabled($admin) ? 'enabled' : ($this->twoFactor->pendingSecret($admin) !== null ? 'enrolling' : 'none'),
            'recovery_codes' => $this->twoFactor->recoveryCodesRemaining($admin),
            'last_login_at' => $admin->last_login_at?->toIso8601String(),
            'last_login_ip' => $admin->last_login_ip,
            'created_at' => $admin->created_at?->toIso8601String(),
            'is_self' => $admin->is($viewer),
        ];
    }

    /** The edit page: the row plus what decides which destructive action is offered. */
    /** @return array<string, mixed> */
    public function detail(SuperAdmin $admin, SuperAdmin $viewer): array
    {
        return $this->row($admin, $viewer) + [
            'never_used' => DeleteSuperAdmin::neverUsed($admin),
            'is_last_active' => $admin->is_active && SuperAdmin::query()->where('is_active', true)->whereKeyNot($admin->getKey())->doesntExist(),
        ];
    }

    public function activeCount(): int
    {
        return SuperAdmin::query()->where('is_active', true)->count();
    }
}
