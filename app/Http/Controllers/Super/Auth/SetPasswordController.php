<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Auth;

use App\Domain\SaaS\Actions\Profile\CompleteSuperPasswordReset;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Auth\SetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Where the set-password link lands (`SuperSetPasswordLink`): the super guard's password reset, reached only from
 * a mailed token. There is deliberately no "forgot password" form on the super login page — a link is sent by a
 * colleague from the Admins screen, which leaves an audit row naming who asked for it.
 */
final class SetPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Super/Auth/SetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function store(SetPasswordRequest $request, CompleteSuperPasswordReset $reset): RedirectResponse
    {
        $status = $reset->handle($request->credentials());

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('super.login')->with('status', __($status));
    }
}
