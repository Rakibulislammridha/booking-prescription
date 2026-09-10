<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super;

use App\Domain\SaaS\Actions\Admins\CreateSuperAdmin;
use App\Domain\SaaS\Actions\Admins\DeleteSuperAdmin;
use App\Domain\SaaS\Actions\Admins\UpdateSuperAdmin;
use App\Domain\SaaS\Queries\SuperAdminDirectory;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Admins\ReauthenticatedRequest;
use App\Http\Requests\Super\Admins\StoreSuperAdminRequest;
use App\Http\Requests\Super\Admins\UpdateSuperAdminRequest;
use App\Models\Central\SuperAdmin;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Platform operators (`public.super_admins`, SCHEMA §2.8) on super.{central}: the list, a new account, the edit
 * page, and removal of one that was never used. Everything that changes an account's ACCESS (deactivate,
 * reactivate, reset the second factor, send a set-password link) is `Admins\AdminAccessController`.
 *
 * Every super admin can manage every other one — there is no role above "operator" (ARCHITECTURE §6.2) — and
 * what stands in for a permission model is the guards inside the actions: not yourself, not the last active
 * account, and never a delete of an account that has left a trail.
 */
final class AdminController extends Controller
{
    public function __construct(private readonly SuperAdminDirectory $directory) {}

    public function index(Request $request): Response
    {
        $viewer = $this->viewer($request);

        return Inertia::render('Super/Admins/Index', [
            'admins' => $this->directory->all($viewer),
            'active_count' => $this->directory->activeCount(),
        ]);
    }

    public function store(StoreSuperAdminRequest $request, CreateSuperAdmin $create): RedirectResponse
    {
        $data = $request->toData();
        $admin = $create->handle($data, $request->admin());

        return redirect()->route('super.admins.edit', ['admin' => $admin->id])
            ->with('flash.success', __($data->sendsLink() ? 'super.admins.flash.created_link' : 'super.admins.flash.created', ['name' => $admin->name]));
    }

    public function edit(Request $request, SuperAdmin $admin): Response
    {
        return Inertia::render('Super/Admins/Edit', [
            'admin' => $this->directory->detail($admin, $this->viewer($request)),
        ]);
    }

    public function update(UpdateSuperAdminRequest $request, SuperAdmin $admin, UpdateSuperAdmin $update): RedirectResponse
    {
        $update->handle($admin, $request->toData(), $request->admin());

        return redirect()->route('super.admins.edit', ['admin' => $admin->id])->with('flash.success', __('super.admins.flash.saved'));
    }

    public function destroy(ReauthenticatedRequest $request, SuperAdmin $admin, DeleteSuperAdmin $delete): RedirectResponse
    {
        $name = $admin->name;
        $delete->handle($admin, $request->admin());

        return redirect()->route('super.admins.index')->with('flash.success', __('super.admins.flash.deleted', ['name' => $name]));
    }

    private function viewer(Request $request): SuperAdmin
    {
        $admin = $request->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
