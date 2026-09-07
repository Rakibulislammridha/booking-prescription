<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Reception\Services\ShiftSummary;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** GET /panel/reception/shift?date= — the shift-close summary page (Reception/Shift). */
final class ShiftController extends Controller
{
    public function index(Request $request, ActiveBranch $activeBranch, ShiftSummary $summary): Response
    {
        $this->authorize('viewAny', Appointment::class);
        $branch = $activeBranch->current() ?? Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail();
        $input = (string) $request->query('date', '');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $input) === 1 ? CarbonImmutable::parse($input, Clock::timezone())->startOfDay() : Clock::today();

        return Inertia::render('Reception/Shift', ['summary' => $summary->build($branch, $date)]);
    }
}
