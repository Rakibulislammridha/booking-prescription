<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Auth;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Auth\ResetPasswordRequest;
use App\Models\Tenant\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Staff password reset form + submit. Routes: panel.password.reset (GET {token}) / panel.password.store (POST).
 */
final class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', ['token' => $token, 'email' => (string) $request->query('email', '')]);
    }

    public function store(ResetPasswordRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $status = Password::broker('users')->reset(
            $request->credentials(),
            function (User $user, string $password) use ($audit): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60), 'must_change_password' => false])->save();
                $audit->record(AuditAction::Update, $user, null, null, ['guard' => 'web', 'event' => 'password_reset']);

                event(new PasswordReset($user));
            },
        );

        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['email' => __($status)]);
        }

        return redirect()->route('panel.login')->with('status', __($status));
    }
}
