<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\AssignCompounder;
use App\Domain\Clinic\Actions\UnassignCompounder;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\DestroyDoctorCompounderRequest;
use App\Http\Requests\Panel\Clinic\StoreDoctorCompounderRequest;
use App\Http\Resources\Clinic\DoctorCompounderResource;
use App\Http\Resources\Clinic\UserResource;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF — "a doctor can assign a compounder and he can manage those specific doctor's patients".
 *
 * The screen lives on the DOCTOR, not on the staff user, because the assignment is the doctor's decision: a doctor
 * administers their own row (DoctorPolicy::manageCompounders, the designPad shape) and a hospital admin administers
 * every row. Nothing here grants anything by itself — the `compounder` role carries the four permissions, and this
 * list decides WHICH doctors those four apply to, through App\Domain\Clinic\Services\DoctorScope.
 *
 * The assignable pool is deliberately narrow: active staff who already hold the compounder role and are not already
 * on this doctor's desk. Making one is the staff screen's job, so a receptionist can never be half-turned into a
 * compounder from here by accident.
 */
final class DoctorCompounderController extends Controller
{
    public function index(Request $request, Doctor $doctor): Response
    {
        $this->authorize('manageCompounders', $doctor);
        /** @var User $user */
        $user = $request->user('web');

        $assigned = $doctor->compounders()->orderBy('users.name')->get();

        // No `id`. The bigint is the tenant schema's business and the screen has never needed it: every link and
        // form on that page addresses this doctor by `public_id` (CONVENTIONS §5), so shipping the internal key as
        // well only put a row identifier into a browser for nothing.
        return Inertia::render('Clinic/Doctors/Compounders', [
            'doctor' => [
                'public_id' => $doctor->public_id,
                'name' => $doctor->name,
                'name_bn' => $doctor->name_bn,
                'code' => $doctor->code,
            ],
            'compounders' => $this->rows($assigned),
            'available' => UserResource::collection($this->pool($assigned))->resolve(),
            'can' => ['manage' => $user->can('manageCompounders', $doctor)],
        ]);
    }

    /**
     * `user_public_id`, not a bigint `user_id`: this screen picks the account out of a list it rendered itself, and
     * everything else in the panel names a staff user by its public ULID — the doctor in this very URL included. The
     * pool is built from UserResource, so the value the form sends is a field the screen already has.
     */
    public function store(StoreDoctorCompounderRequest $request, Doctor $doctor, AssignCompounder $assign): RedirectResponse
    {
        $compounder = User::query()->where('public_id', (string) $request->validated('user_public_id'))->firstOrFail();
        $assign->handle($doctor, $compounder, Actor::fromRequest($request));

        return back()->with('flash.success', __('clinic.compounders.flash.assigned', ['name' => $compounder->name]));
    }

    /** `$compounder` is resolved THROUGH the doctor (scoped binding), so someone else's compounder 404s here. */
    public function destroy(DestroyDoctorCompounderRequest $request, Doctor $doctor, User $compounder, UnassignCompounder $unassign): RedirectResponse
    {
        $unassign->handle($doctor, $compounder, Actor::fromRequest($request));

        return back()->with('flash.success', __('clinic.compounders.flash.removed', ['name' => $compounder->name]));
    }

    /**
     * Active compounders not already on this desk. `users` is a tenant-schema table under the tenant assertion
     * scope, so "never leak another clinic's staff" is structural here, not a filter anyone has to remember.
     *
     * @param  Collection<int, User>  $assigned
     * @return Collection<int, User>
     */
    private function pool(Collection $assigned): Collection
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn (Builder $q) => $q->where('name', Role::Compounder->value))
            ->whereKeyNot($assigned->modelKeys())
            ->orderBy('name')
            ->get();
    }

    /**
     * The assignment metadata lives on a pivot with no model of its own, so it is read here — once per row, with
     * the handful of assigner ids resolved to names in a single extra query rather than one per row.
     *
     * @param  Collection<int, User>  $assigned
     * @return array<int, array<string, mixed>>
     */
    private function rows(Collection $assigned): array
    {
        $assignerIds = $assigned
            ->map(fn (User $u): mixed => $this->pivotOf($u)?->getAttribute('assigned_by_user_id'))
            ->filter()->map(fn (mixed $id): int => (int) $id)->unique()->values()->all();

        /** @var array<int, string> $names */
        $names = $assignerIds === [] ? [] : User::query()->whereKey($assignerIds)->pluck('name', 'id')->all();

        return $assigned->map(function (User $u) use ($names): array {
            $pivot = $this->pivotOf($u);
            $assignedAt = $pivot?->getAttribute('created_at');
            $assignedBy = $pivot?->getAttribute('assigned_by_user_id');

            return (new DoctorCompounderResource(
                $u,
                $assignedAt instanceof DateTimeInterface ? CarbonImmutable::instance($assignedAt)->toIso8601ZuluString() : null,
                $assignedBy === null ? null : ($names[(int) $assignedBy] ?? null),
            ))->resolve();
        })->values()->all();
    }

    private function pivotOf(User $user): ?Pivot
    {
        $pivot = $user->relationLoaded('pivot') ? $user->getRelation('pivot') : null;

        return $pivot instanceof Pivot ? $pivot : null;
    }
}
