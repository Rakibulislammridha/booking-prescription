<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Billing;

use App\Domain\Billing\Actions\CloseCashShift;
use App\Domain\Billing\Actions\OpenCashShift;
use App\Domain\Billing\Services\CurrentShift;
use App\Domain\Billing\Services\ShiftReconciler;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Billing\CashShiftRequest;
use App\Http\Resources\Billing\CashShiftResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\CashShift;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cash drawer: open, live expected-vs-collected, close with a counted amount (BRIEF §5.F).
 *
 * The "recent" list is the branch's last thirty drawers — every cashier's. A DoctorScope-restricted caller is not a
 * supervisor of the branch's cash (CashShiftPolicy says as much for close), so they are shown their own drawers and
 * nobody else's; opening and closing are already their-own-shift-only.
 */
final class CashShiftController extends Controller
{
    public function index(Request $request, CurrentShift $current, ShiftReconciler $reconciler, ActiveBranch $activeBranch, DoctorScope $scope): Response
    {
        $this->authorize('viewAny', CashShift::class);
        /** @var User $user */
        $user = $request->user('web');

        $open = $current->forUser($user->id);
        $branch = $activeBranch->current();

        $recent = CashShift::query()
            ->with(['user', 'branch'])
            ->when($branch !== null, fn ($q) => $q->where('branch_id', $branch->id))
            ->when($scope->doctorIds($user) !== null, fn ($q) => $q->where('user_id', $user->id))
            ->orderByDesc('opened_at')
            ->limit(30)
            ->get();

        return Inertia::render('Billing/Shift', [
            'open_shift' => $open === null ? null : (new CashShiftResource($open->load(['user', 'branch'])))->resolve(),
            'live_totals' => $open === null ? null : $reconciler->totals($open),
            'recent' => CashShiftResource::collection($recent)->resolve(),
            'active_branch' => $branch === null ? null : ['public_id' => $branch->public_id, 'name' => $branch->name],
            'can' => ['open' => $user->can('open', CashShift::class)],
        ]);
    }

    public function open(CashShiftRequest $request, OpenCashShift $action, ActiveBranch $activeBranch): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user('web');
        $branchId = $request->validated('branch');
        $branch = $branchId === null
            ? ($activeBranch->current() ?? Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail())
            : Branch::query()->where('public_id', (string) $branchId)->firstOrFail();

        $action->handle($user, $branch, (int) ($request->validated('opening_float_paisa') ?? 0), Actor::fromRequest($request));

        return back()->with('flash.success', __('billing.flash.shift_opened'));
    }

    public function close(CashShiftRequest $request, CashShift $shift, CloseCashShift $action): RedirectResponse
    {
        $action->handle($shift, (int) ($request->validated('counted_cash_paisa') ?? 0), Actor::fromRequest($request), $request->validated('note'));

        return back()->with('flash.success', __('billing.flash.shift_closed'));
    }
}
