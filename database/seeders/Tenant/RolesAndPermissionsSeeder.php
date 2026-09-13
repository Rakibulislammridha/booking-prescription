<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Support\RoleMatrix;
use App\Models\Tenant\Permission as PermissionModel;
use App\Models\Tenant\Role as RoleModel;
use Illuminate\Database\Seeder;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates every Permission enum case, every Role enum case, and syncs the RoleMatrix. Idempotent; runs on every deploy
 * that adds a permission (ProvisionTenant and tenants:seed --class=RolesAndPermissionsSeeder).
 */
final class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permission::cases() as $permission) {
            PermissionModel::query()->firstOrCreate(['name' => $permission->value, 'guard_name' => 'web']);
        }

        foreach (Role::cases() as $role) {
            /** @var RoleModel $model */
            $model = RoleModel::query()->firstOrCreate(['name' => $role->value, 'guard_name' => 'web']);
            $model->syncPermissions(array_map(fn (Permission $p) => $p->value, RoleMatrix::permissionsFor($role)));
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
