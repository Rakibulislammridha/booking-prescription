<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Settings;

use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/**
 * One platform settings key, from the console's Platform settings screen (`PUT settings/{key}`).
 *
 * A key the registry marks `reauth` (every `security.*` key) re-asks the operator's CURRENT PASSWORD — and only
 * the password, not a TOTP code: the first such key is the switch that turns the second factor off for the whole
 * console, and a switch that needs the thing it disables would be no switch at all. The value itself is
 * type-checked by the registry inside the action, so an unknown key or a bad value is a domain error, not a
 * validation rule duplicated here.
 */
final class UpdatePlatformSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $key = $this->key();
        $reauth = PlatformSettingsRegistry::has($key) && PlatformSettingsRegistry::requiresPassword($key);

        return [
            'value' => ['present'],
            'password' => $reauth ? ['required', 'string', 'current_password:super'] : ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => __('validation.auth.password_required'),
            'password.current_password' => __('auth.password_incorrect'),
        ];
    }

    public function key(): string
    {
        return (string) $this->route('key');
    }

    /**
     * The submitted value, as typed by the registry. A free-text key's empty box arrives as null after
     * ConvertEmptyStringsToNull and means an empty string; a blank SECRET stays null, which the service reads as
     * "keep what is stored".
     */
    public function value(): mixed
    {
        $value = $this->input('value');
        $key = $this->key();

        if ($value === null && PlatformSettingsRegistry::has($key) && ! PlatformSettingsRegistry::isSecret($key)
            && PlatformSettingsRegistry::definition($key)['type'] === 'string') {
            return '';
        }

        return $value;
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
