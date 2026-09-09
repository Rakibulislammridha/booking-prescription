<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Telemedicine;

use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Services\JoinLink;
use App\Http\Controllers\Controller;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * `GET /telemedicine/j/{room}` — the link in the patient's SMS (ARCHITECTURE §6: the patient joins on the
 * `patient` guard from the public site).
 *
 * Possession of a validly signed, unexpired link for a room whose appointment names this patient IS the
 * authentication: the patient is logged in on the `patient` guard and sent to the waiting room. That is the same
 * trust model as the OTP the portal already uses — an SMS to the registered mobile — with the code baked into
 * the URL, and it matters because a patient fighting an OTP form on a 2G phone thirty seconds before their
 * consultation is a patient who misses it.
 *
 * Four ways this can fail, all of them answered with a page rather than a redirect loop:
 *   - the signature does not verify (tampered room name or expiry)  → 403 page
 *   - the link has expired                                          → 403 page, "ask the clinic for a new link"
 *   - the room ended or was cancelled                               → 410 page, the consultation is over
 */
final class JoinLinkController extends Controller
{
    public function __invoke(Request $request, TelemedicineRoom $room, JoinLink $links): RedirectResponse|SymfonyResponse
    {
        if (! $links->isValid($request)) {
            return $this->refuse('telemedicine.join.invalid_link', 403);
        }

        if ($room->status->isTerminal()) {
            return $this->refuse($room->status === RoomStatus::Cancelled ? 'telemedicine.join.room_cancelled' : 'telemedicine.join.room_ended', 410);
        }

        // No remember-me: a Patient has no remember_token (OTP-only identity), so `remember: true` only set a
        // 400-day recaller cookie with an empty token that could never authenticate (B8). A consultation link is a
        // short-lived, single-visit credential — it should not try to create a durable session anyway.
        Auth::guard('patient')->login($room->appointment->patient);
        $request->session()->regenerate();

        return redirect()->route('site.telemedicine.room', ['room' => $room->room_name]);
    }

    private function refuse(string $key, int $status): SymfonyResponse
    {
        return Inertia::render('Telemedicine/LinkProblem', ['reason' => $key, 'message' => __($key)])
            ->toResponse(request())
            ->setStatusCode($status);
    }
}
