<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Notifications;

use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * `PUT notifications/settings {values: {key: value}}` — a batch of `notifications`-screen keys saved together (an
 * identity form, a gateway form, one template's four fields). Each value is type-checked by the registry inside
 * the action; here we only refuse keys that are not on this screen, so the Platform settings page's keys — the
 * security switch in particular — cannot be written from this form.
 */
final class UpdateNotificationSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'values' => ['required', 'array', 'min:1', 'max:40'],
            'values.*' => ['nullable'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            foreach (array_keys((array) $this->input('values', [])) as $key) {
                if (! is_string($key) || ! PlatformSettingsRegistry::has($key) || PlatformSettingsRegistry::screenOf($key) !== 'notifications') {
                    $v->errors()->add('values', __('super.notifications.validation.unknown_key', ['key' => (string) $key]));
                }
            }
        });
    }

    /**
     * Key → submitted value. A blank string on a string key is an empty string (ConvertEmptyStringsToNull made it
     * null); a blank SECRET stays null, which the service reads as "keep what is stored".
     *
     * @return array<string, mixed>
     */
    public function values(): array
    {
        $out = [];

        foreach ((array) $this->validated('values') as $key => $value) {
            $key = (string) $key;

            if ($value === null && ! PlatformSettingsRegistry::isSecret($key) && PlatformSettingsRegistry::definition($key)['type'] === 'string') {
                $value = '';
            }

            $out[$key] = $value;
        }

        return $out;
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
