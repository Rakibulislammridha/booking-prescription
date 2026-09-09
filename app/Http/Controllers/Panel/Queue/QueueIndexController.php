<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Queue;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * GET /panel/queue (`panel.queue.index`) — where the sidebar's "Live queue" lands. There is no third queue page: a
 * user who is a doctor is sent to their own call-next screen (REALTIME.md §11 — the screen they work in all session,
 * and the only doctor screen they may open), everyone else to the branch overview (`panel.queue.today`), which
 * links into each doctor screen. Two existing pages, one entry point.
 */
final class QueueIndexController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user('web');
        $isDoctor = $user instanceof User && Doctor::query()->where('user_id', $user->id)->exists();

        return redirect()->route($isDoctor ? 'panel.queue.doctor' : 'panel.queue.today');
    }
}
