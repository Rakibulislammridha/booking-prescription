<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Reception\Services\BoardBuilder;
use App\Domain\Reception\Services\PrintTemplates;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\Serial;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Today's board: Inertia page (Reception/Board) and the JSON the page polls in degraded mode (panel.reception.board.data).
 *
 * Both entries are the same document and both narrow it the same way — the caller's DoctorScope goes into
 * BoardBuilder, so a compounder's board holds their doctors' sessions and nothing else. Scoping only the page and
 * not the 5 s poll would be no scoping at all.
 */
final class BoardController extends Controller
{
    public function index(Request $request, ActiveBranch $activeBranch, BoardBuilder $board, PrintTemplates $templates, Settings $settings, DoctorScope $scope): Response
    {
        $this->authorize('viewAny', Appointment::class);
        $branch = $this->branch($activeBranch);
        $date = $this->date($request);
        $user = $request->user('web');
        $tenant = Tenancy::current();
        // Asked ONCE, and answered twice: the same list narrows the document and tells the page that it is narrowed.
        // The page used to re-derive "am I scoped?" from the shared auth props (`roles.includes('compounder') &&
        // !hospital_admin`) — a second copy of DoctorScope's rule, in the client, where it decided not only the
        // banner's wording but whether the desk would open the device cache at all. A client that guesses this
        // wrong reads a board it may not have; a second implementation of a boundary is the way it guesses wrong.
        $doctorIds = $user === null ? [] : $scope->doctorIds($user);

        return Inertia::render('Reception/Board', [
            'board' => $board->build($branch, $date, doctorIds: $doctorIds),
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
                'record_vitals' => $user?->can('prescriptions.vitals.record') ?? false,
                // The quick-search box had no flag, so it rendered for everyone and then swallowed its own 403
                // (PatientLookupController authorises `viewAny` on Patient, which a compounder does not hold): the
                // box sat there answering "no patients" for ever, and the dues panel beside it — whose only input is
                // a patient picked HERE — sat there with nothing to show. A shorter column is the honest screen.
                'search_patients' => $user?->can('viewAny', Patient::class) ?? false,
                // The arrival tick had no flag at all, so it rendered for anyone who could open the board — an
                // accountant included. It is its own ability now, and the row-level answer is still
                // SerialPolicy::checkIn: this only decides whether the desk is offered the button.
                //
                // It asks the POLICY (SerialPolicy::checkInAny) rather than the bare `serials.check-in` permission,
                // because the permission alone is not the rule: checkIn() OR-s in the three roles that held the move
                // before the permission existed, for tenants whose seeder has not run (its docblock says why). Asking
                // the permission here answered false on exactly those tenants — every row kept its 403-free POST and
                // not one row rendered the tick, which is the dead front desk the fallback exists to prevent.
                'check_in' => $user?->can('checkInAny', Serial::class) ?? false,
                // The kiosk QR mints a signed public booking link for a session, which is issuing serials by
                // another name — same permission as issuing them at the counter, and the route asserts it too.
                'kiosk' => $user?->can('serials.issue.counter') ?? false,
                // BRIEF §5.G.4: the sheet is printed at the desk too. Printing an issued prescription is the
                // `view` ability of PrescriptionPolicy (what panel.prescription.print authorises and audits as
                // `print`); `viewAny` is the same three grants without a row in hand, so it is the board's flag.
                'print_prescription' => $user?->can('viewAny', Prescription::class) ?? false,
            ],
            'actor_public_id' => $user?->public_id,
            // Is THIS viewer narrowed? The desk is offline-first and its Dexie cache is keyed by (tenant, device),
            // never by person — so a restricted viewer on a registered reception tablet would otherwise render the
            // previous receptionist's unscoped board out of that cache, for ever, because every device endpoint
            // 403s for them and the hook fell back to the cache. The page uses this to stay on the scoped JSON and
            // never open the device path at all. `null` (no web user) is restricted too, matching the `[]` above.
            'doctor_scoped' => $doctorIds !== null,
        ]);
    }

    public function data(Request $request, ActiveBranch $activeBranch, BoardBuilder $board, DoctorScope $scope): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $user = $request->user('web');

        return response()->json($board->build($this->branch($activeBranch), $this->date($request), doctorIds: $user === null ? [] : $scope->doctorIds($user)))
            ->header('Cache-Control', 'no-store');
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
