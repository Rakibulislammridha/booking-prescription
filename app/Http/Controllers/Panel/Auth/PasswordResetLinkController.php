<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff "forgot password" (broker `users` → provider staff, table password_reset_tokens in the tenant schema).
 * Routes: panel.password.request (GET) / panel.password.email (POST). The reset link targets panel.password.reset
 * on this tenant host (AuthServiceProvider: ResetPassword::createUrlUsing).
 */
final class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => session('status')]);
    }

    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $status = Password::broker('users')->sendResetLink(['email' => $request->email()]);

        if ($status !== Password::RESET_LINK_SENT) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return back()->with('status', __($status));
    }
}
