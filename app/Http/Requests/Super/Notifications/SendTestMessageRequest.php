<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Notifications;

use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * `POST notifications/test {channel, recipient?, message?}` — "send a test to me". Email goes to the signed-in
 * operator unless another address is given; SMS needs a Bangladeshi mobile (super admins carry no mobile number).
 */
final class SendTestMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in(['email', 'sms'])],
            // A Bangladeshi mobile as people type it: 017…, 8801…, +8801…; normalised to E.164 by recipient().
            'recipient' => $this->input('channel') === 'sms'
                ? ['required', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/']
                : ['nullable', 'email:rfc', 'max:255'],
            'message' => ['nullable', 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['recipient.regex' => __('saas.onboarding.mobile_invalid')];
    }

    public function channel(): string
    {
        return (string) $this->validated('channel');
    }

    public function recipient(): string
    {
        $recipient = trim((string) $this->validated('recipient'));

        if ($this->channel() === 'sms') {
            $digits = (string) preg_replace('/\D+/', '', $recipient);

            return '+'.(str_starts_with($digits, '880') ? $digits : '88'.$digits);      // 01712345678 → +8801712345678
        }

        return $recipient !== '' ? $recipient : $this->admin()->email;
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
