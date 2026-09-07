<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Reception\Services\BoardBuilder;
use App\Domain\Reception\Services\PrintTemplates;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\ReceptionDevice;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Today's board: Inertia page (Reception/Board) and the JSON the page polls in degraded mode (panel.reception.board.data). */
final class BoardController extends Controller
{
    public function index(Request $request, ActiveBranch $activeBranch, BoardBuilder $board, PrintTemplates $templates, Settings $settings): Response
    {
        $this->authorize('viewAny', Appointment::class);
        $branch = $this->branch($activeBranch);
        $date = $this->date($request);
        $user = $request->user('web');
        $tenant = Tenancy::current();

        return Inertia::render('Reception/Board', [
            'board' => $board->build($branch, $date),
            'tenant_public_id' => $tenant?->public_id,
            'channel' => $tenant === null ? null : "tenant.{$tenant->public_id}.reception.{$branch->public_id}",
            'print_format' => $templates->defaultFormat($branch),
            'settings' => array_merge($settings->withPrefix('serial.'), $settings->withPrefix('reception.'), $settings->withPrefix('kiosk.')),
            'can' => [
                'issue' => $user?->can('serials.issue.counter') ?? false,
                'call_next' => $user?->can('queue.call-next') ?? false,
                'cancel' => $user?->can('serials.cancel') ?? false,
                'collect' => $user?->can('billing.payments.collect') ?? false,
                'register_device' => $user?->can('register', ReceptionDevice::class) ?? false,
                'revoke' => $user?->can('reception.blocks.revoke') ?? false,
            ],
            'actor_public_id' => $user?->public_id,
        ]);
    }

    public function data(Request $request, ActiveBranch $activeBranch, BoardBuilder $board): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);

        return response()->json($board->build($this->branch($activeBranch), $this->date($request)))->header('Cache-Control', 'no-store');
    }

    private function branch(ActiveBranch $activeBranch): Branch
    {
        return $activeBranch->current() ?? Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail();
    }

    private function date(Request $request): CarbonImmutable
    {
        $date = (string) $request->query('date', '');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? CarbonImmutable::parse($date, Clock::timezone())->startOfDay() : Clock::today();
    }
}
