<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Clinic;

use App\Domain\Clinic\Actions\CreateHoliday;
use App\Domain\Clinic\Actions\DeleteHoliday;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Clinic\StoreHolidayRequest;
use App\Http\Resources\Clinic\BranchResource;
use App\Http\Resources\Clinic\HolidayResource;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\User;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * BRIEF §5.A — the holiday calendar, one year at a time. A holiday is either clinic-wide (`branch_id` null) or
 * specific to one branch; session materialisation skips these days (SCHEMA §3.1), which is why the year view shows
 * both kinds on the same grid.
 */
final class HolidayController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Holiday::class);
        /** @var User $user */
        $user = $request->user('web');
        $year = (int) $request->query('year', (string) Clock::today()->year);
        $year = $year >= 2000 && $year <= 2100 ? $year : Clock::today()->year;
        $branchId = (int) $request->query('branch', 0);

        $holidays = Holiday::query()
            ->with('branch')
            ->whereBetween('holiday_date', ["{$year}-01-01", "{$year}-12-31"])
            ->when($branchId > 0, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
            ->orderBy('holiday_date')
            ->get();

        return Inertia::render('Clinic/Holidays/Index', [
            'holidays' => HolidayResource::collection($holidays)->resolve(),
            'branch_options' => BranchResource::collection(Branch::query()->orderByDesc('is_main')->orderBy('name')->get())->resolve(),
            'filters' => ['year' => $year, 'branch' => $branchId ?: null],
            'today' => Clock::today()->toDateString(),
            'can' => ['manage' => $user->can('create', Holiday::class)],
        ]);
    }

    public function store(StoreHolidayRequest $request, CreateHoliday $create): RedirectResponse
    {
        $holiday = $create->handle($request->toData(), Actor::fromRequest($request));

        return back()->with('flash.success', __('clinic.holidays.flash.created', ['name' => $holiday->name]));
    }

    public function destroy(Request $request, Holiday $holiday, DeleteHoliday $delete): RedirectResponse
    {
        $this->authorize('delete', $holiday);
        $delete->handle($holiday, Actor::fromRequest($request));

        return back()->with('flash.success', __('clinic.holidays.flash.deleted', ['name' => $holiday->name]));
    }
}
