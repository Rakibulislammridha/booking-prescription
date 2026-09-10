<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Admins;

use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A credential-grade action on ANOTHER operator's account — resetting their second factor, deleting the account —
 * re-asks the acting operator's own password, so an unattended console is not one click away from stripping a
 * colleague's factor (the same reasoning as DisableTwoFactorRequest, without the code: the code that would be
 * asked for belongs to the person who has lost it).
 */
final class ReauthenticatedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:super']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => __('validation.auth.password_required'),
            'password.current_password' => __('auth.password_incorrect'),
        ];
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
