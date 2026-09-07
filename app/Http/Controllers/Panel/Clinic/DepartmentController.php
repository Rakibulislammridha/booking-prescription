<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\CreateDepartment;
use App\Domain\Clinic\Actions\DeleteDepartment;
use App\Domain\Clinic\Actions\UpdateDepartment;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\StoreDepartmentRequest;
use App\Http\Requests\Panel\Clinic\UpdateDepartmentRequest;
use App\Http\Resources\Clinic\BranchResource;
use App\Http\Resources\Clinic\DepartmentResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A — departments. One screen: the list is the editor (a clinic has a dozen of these, not a thousand),
 * rows open an inline dialog. Both names are kept (`name`, `name_bn` — SCHEMA §3.1, as `specialties` does): the
 * English one is what a doctor's profile and an export print, the Bangla one is what the clinic's own staff and
 * the public site read.
 */
final class DepartmentController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Department::class);
        /** @var User $user */
        $user = $request->user('web');

        return Inertia::render('Clinic/Departments/Index', [
            'departments' => DepartmentResource::collection(
                Department::query()->with('branch')->orderBy('sort_order')->orderBy('name')->get()
            )->resolve(),
            'branch_options' => BranchResource::collection(Branch::query()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
            'can' => ['manage' => $user->can('create', Department::class)],
        ]);
    }

    public function store(StoreDepartmentRequest $request, CreateDepartment $create): RedirectResponse
    {
        $department = $create->handle($request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.departments.index')->with('flash.success', __('clinic.departments.flash.created', ['name' => $department->name]));
    }

    public function update(UpdateDepartmentRequest $request, Department $department, UpdateDepartment $update): RedirectResponse
    {
        $update->handle($department, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.departments.index')->with('flash.success', __('clinic.departments.flash.updated', ['name' => $department->name]));
    }

    public function destroy(Request $request, Department $department, DeleteDepartment $delete): RedirectResponse
    {
        $this->authorize('delete', $department);
        $delete->handle($department, Actor::fromRequest($request));

        return redirect()->route('panel.clinic.departments.index')->with('flash.success', __('clinic.departments.flash.deleted', ['name' => $department->name]));
    }
}
