<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Scheduling;

use App\Domain\Scheduling\Actions\CreateScheduleOverride;
use App\Domain\Scheduling\Actions\DeleteScheduleOverride;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Scheduling\StoreScheduleOverrideRequest;
use App\Models\Tenant\ScheduleOverride;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** Per-date overrides (late start, cut short, cancelled, capacity change, time change, extra session). */
final class OverrideController extends Controller
{
    public function store(StoreScheduleOverrideRequest $request, CreateScheduleOverride $action): RedirectResponse
    {
        $result = $action->handle($request->toData(), Actor::fromRequest($request));
        $override = $result['override'];

        return redirect()->route('panel.scheduling.index', ['doctor' => $override->doctor_id, 'branch' => $override->branch_id])
            ->with('flash.success', __('scheduling.flash.override_saved', ['applied' => implode(', ', array_map(fn (string $code, string $outcome) => "{$code}: {$outcome}", array_keys($result['applied']), $result['applied'])) ?: '-']));
    }

    public function destroy(Request $request, ScheduleOverride $override, DeleteScheduleOverride $action): RedirectResponse
    {
        $this->authorize('delete', $override);
        $action->handle($override, Actor::fromRequest($request));

        return redirect()->route('panel.scheduling.index', ['doctor' => $override->doctor_id, 'branch' => $override->branch_id])->with('flash.success', __('scheduling.flash.override_removed'));
    }
}
