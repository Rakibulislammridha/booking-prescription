<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Reception;

use App\Domain\Booking\Services\KioskLink;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /panel/reception/kiosk-url?session= — the signed QR link the desk displays (SERIAL_ENGINE §11.2). */
final class KioskController extends Controller
{
    public function __invoke(Request $request, ActiveBranch $activeBranch, KioskLink $link): JsonResponse
    {
        $this->authorize('viewAny', Appointment::class);
        $branch = $activeBranch->current() ?? Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail();
        $sessionId = (string) $request->query('session', '');
        $session = $sessionId === '' ? null : SessionInstance::query()->where('public_id', $sessionId)->first();

        return response()->json(['url' => $link->url($branch, $session), 'expires_in_hours' => KioskLink::HOURS]);
    }
}
