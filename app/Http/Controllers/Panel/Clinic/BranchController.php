<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\CreateBranch;
use App\Domain\Clinic\Actions\UpdateBranch;
use App\Domain\Clinic\Data\BranchData;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\StoreBranchRequest;
use App\Http\Requests\Panel\Clinic\UpdateBranchRequest;
use App\Http\Requests\Panel\Clinic\UpdateBranchStatusRequest;
use App\Http\Resources\Clinic\BranchResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A — branch management: list, create, edit, activate/deactivate, mark main. The single-main invariant and
 * the "first branch is always main" rule live in CreateBranch/UpdateBranch; this controller only routes to them.
 */
final class BranchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Branch::class);
        /** @var User $user */
        $user = $request->user('web');
        $q = trim((string) $request->query('q', ''));

        $branches = Branch::query()
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('code', 'ilike', "%{$q}%")->orWhere('address', 'ilike', "%{$q}%")))
            ->orderByDesc('is_main')->orderBy('name')
            ->paginate(25)->withQueryString();

        return Inertia::render('Clinic/Branches/Index', [
            // The `{data, links, meta}` envelope of CONVENTIONS §13, which is what `Paginated<T>` describes
            // client-side — `->through()` alone would serialise the paginator flat and the pager would find no meta.
            'branches' => BranchResource::collection($branches)->response()->getData(true),
            'filters' => ['q' => $q],
            'timezone' => Tenancy::current()->timezone ?? config('app.timezone'),
            'can' => ['manage' => $user->can('create', Branch::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Branch::class);

        return Inertia::render('Clinic/Branches/Create', [
            'timezone' => Tenancy::current()->timezone ?? config('app.timezone'),
            'has_branches' => Branch::query()->exists(),
        ]);
    }

    public function store(StoreBranchRequest $request, CreateBranch $create): RedirectResponse
    {
        $branch = $create->handle($request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.branches.index')->with('flash.success', __('clinic.branches.flash.created', ['name' => $branch->name]));
    }

    public function edit(Branch $branch): Response
    {
        $this->authorize('update', $branch);

        return Inertia::render('Clinic/Branches/Edit', [
            'branch' => (new BranchResource($branch))->resolve(),
            'timezone' => Tenancy::current()->timezone ?? config('app.timezone'),
        ]);
    }

    public function update(UpdateBranchRequest $request, Branch $branch, UpdateBranch $update): RedirectResponse
    {
        $update->handle($branch, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.branches.index')->with('flash.success', __('clinic.branches.flash.updated', ['name' => $branch->name]));
    }

    /** Activate / deactivate without re-posting the form; the branch's own data is replayed unchanged. */
    public function status(UpdateBranchStatusRequest $request, Branch $branch, UpdateBranch $update): RedirectResponse
    {
        $update->handle($branch, self::dataFor($branch, isActive: $request->isActive()), Actor::fromRequest($request));

        return back()->with('flash.success', __($request->isActive() ? 'clinic.branches.flash.activated' : 'clinic.branches.flash.deactivated', ['name' => $branch->name]));
    }

    /** Mark main: UpdateBranch demotes whichever branch held the flag, inside one transaction. */
    public function main(Request $request, Branch $branch, UpdateBranch $update): RedirectResponse
    {
        $this->authorize('update', $branch);
        $update->handle($branch, self::dataFor($branch, isMain: true), Actor::fromRequest($request));

        return back()->with('flash.success', __('clinic.branches.flash.main_set', ['name' => $branch->name]));
    }

    private static function dataFor(Branch $branch, ?bool $isActive = null, ?bool $isMain = null): BranchData
    {
        return new BranchData(
            name: $branch->name,
            code: $branch->code,
            slug: $branch->slug,
            address: $branch->address,
            phone: $branch->phone,
            email: $branch->email,
            isMain: $isMain ?? $branch->is_main,
            isActive: $isActive ?? $branch->is_active,
            geo: $branch->geo,
            settings: $branch->settings,
        );
    }
}
