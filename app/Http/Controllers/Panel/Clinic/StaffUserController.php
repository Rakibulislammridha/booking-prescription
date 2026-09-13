<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\CreateStaffUser;
use App\Domain\Clinic\Actions\UpdateStaffUser;
use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\StoreStaffUserRequest;
use App\Http\Requests\Panel\Clinic\UpdateStaffStatusRequest;
use App\Http\Requests\Panel\Clinic\UpdateStaffUserRequest;
use App\Http\Resources\Clinic\BranchResource;
use App\Http\Resources\Clinic\UserResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Role as RoleRecord;
use App\Models\Tenant\User;
use Illuminate\Contracts\Auth\PasswordBroker;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A — staff accounts: list by role with search, create with role + default branch + mobile + locale, edit,
 * deactivate, trigger a password reset. The two self-lockout guards (deactivating yourself, taking your own admin
 * role away) are enforced by UpdateStaffUser for every caller, not by this controller.
 */
final class StaffUserController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', User::class);
        /** @var User $user */
        $user = $request->user('web');
        $q = trim((string) $request->query('q', ''));
        $role = (string) $request->query('role', '');
        $role = in_array($role, Role::values(), true) ? $role : '';
        $status = (string) $request->query('status', '');

        $users = User::query()
            ->with(['defaultBranch', 'doctor'])
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('name', 'ilike', "%{$q}%")->orWhere('email', 'ilike', "%{$q}%")->orWhere('mobile', 'ilike', "%{$q}%")))
            ->when($role !== '', fn (Builder $query) => $query->whereHas('roles', fn (Builder $r) => $r->where('name', $role)))
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->orderBy('name')
            ->paginate(self::PER_PAGE)->withQueryString();

        return Inertia::render('Clinic/Staff/Index', [
            'users' => UserResource::collection($users)->response()->getData(true),
            'filters' => ['q' => $q, 'role' => $role, 'status' => $status],
            'roles' => self::roleOptions(),
            'current_user_id' => $user->id,
            'can' => ['manage' => $user->can('create', User::class)],
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', User::class);

        return Inertia::render('Clinic/Staff/Create', [
            'branch_options' => BranchResource::collection(Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
            'roles' => self::roleOptions(),
        ]);
    }

    public function store(StoreStaffUserRequest $request, CreateStaffUser $create): RedirectResponse
    {
        $user = $create->handle($request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.staff.index')->with('flash.success', __('clinic.staff.flash.created', ['name' => $user->name]));
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorize('update', $user);
        /** @var User $actor */
        $actor = $request->user('web');
        $user->load(['defaultBranch', 'doctor']);

        return Inertia::render('Clinic/Staff/Edit', [
            'user' => (new UserResource($user))->resolve(),
            'branch_options' => BranchResource::collection(Branch::query()->active()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
            'roles' => self::roleOptions(),
            'is_self' => $actor->id === $user->id,
        ]);
    }

    public function update(UpdateStaffUserRequest $request, User $user, UpdateStaffUser $update): RedirectResponse
    {
        $update->handle($user, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.clinic.staff.index')->with('flash.success', __('clinic.staff.flash.updated', ['name' => $user->name]));
    }

    public function status(UpdateStaffStatusRequest $request, User $user, UpdateStaffUser $update): RedirectResponse
    {
        $update->handle($user, self::dataFor($user, $request->isActive()), Actor::fromRequest($request));

        return back()->with('flash.success', __($request->isActive() ? 'clinic.staff.flash.activated' : 'clinic.staff.flash.deactivated', ['name' => $user->name]));
    }

    /**
     * "Send a reset link" from the staff list — the clinic never types a password for someone else. The link goes to
     * this tenant host's panel.password.reset (AuthServiceProvider), broker `users`.
     */
    public function sendPasswordReset(Request $request, User $user): RedirectResponse
    {
        $this->authorize('update', $user);

        /** @var PasswordBroker $broker */
        $broker = Password::broker('users');
        $status = $broker->sendResetLink(['email' => $user->email]);

        return $status === Password::RESET_LINK_SENT
            ? back()->with('flash.success', __('clinic.staff.flash.reset_sent', ['email' => $user->email]))
            : back()->with('flash.error', __($status));
    }

    /**
     * The role picker offers the roles this CLINIC has, not the roles this RELEASE knows about.
     *
     * It shipped the enum, and the two are the same list only once RolesAndPermissionsSeeder has run in the
     * tenant's schema. `tenants:migrate --seed` skips suspended tenants (OPERATIONS §2.2), so a clinic reactivated
     * after a release that adds a role has the migrations and not the row: the newest role — today `compounder` —
     * was offered on this form, and CreateStaffUser's syncRoles() then threw Spatie's RoleDoesNotExist as an
     * unhandled 500, on the very screen an admin would open while repairing that tenant. SerialPolicy::checkIn
     * carries a careful fallback for the same tenant; this screen had none.
     *
     * The intersection keeps enum order, which is the insertion order ClinicActionsTest pins the seeded rows to,
     * and the guard is the staff one — a role row for another guard is not a role this form can assign. The
     * renderable in bootstrap/app.php is the second line of defence, and covers every other way into a missing
     * role or permission.
     *
     * @return array<int, string>
     */
    private static function roleOptions(): array
    {
        /** @var array<int, string> $seeded */
        $seeded = RoleRecord::query()->where('guard_name', 'web')->pluck('name')->all();

        return array_values(array_intersect(Role::values(), $seeded));
    }

    private static function dataFor(User $user, bool $isActive): StaffUserData
    {
        $role = $user->getRoleNames()->first();

        return new StaffUserData(
            name: $user->name,
            email: $user->email,
            role: Role::from(is_string($role) ? $role : Role::Receptionist->value),
            password: null,
            mobile: $user->mobile,
            defaultBranchId: $user->default_branch_id,
            locale: $user->locale->value,
            isActive: $isActive,
            mustChangePassword: $user->must_change_password,
            sessionTimeoutMinutes: $user->session_timeout_minutes,
        );
    }
}
