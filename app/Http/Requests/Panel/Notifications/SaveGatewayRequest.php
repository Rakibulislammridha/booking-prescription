<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Notifications;

use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Models\Tenant\SmsGatewaySetting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gateway = $this->route('gateway');

        return $gateway instanceof SmsGatewaySetting
            ? ($this->user('web')?->can('update', $gateway) ?? false)
            : ($this->user('web')?->can('create', SmsGatewaySetting::class) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'channel' => ['required', Rule::in([NotificationChannel::Sms->value, NotificationChannel::Whatsapp->value, NotificationChannel::Ivr->value])],
            'provider' => ['required', Rule::enum(GatewayProvider::class)],
            'name' => ['required', 'string', 'max:80'],
            'sender_id' => ['nullable', 'string', 'max:20'],
            'priority' => ['sometimes', 'integer', 'between:1,999'],
            'is_default' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'options' => ['sometimes', 'array'],
            'options.unicode' => ['sometimes', 'boolean'],
            'options.rate_limit_per_sec' => ['sometimes', 'nullable', 'integer', 'between:1,1000'],
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /** @return array<string, mixed> */
    public function attributes(): array
    {
        return [
            'channel' => $this->input('channel'),
            'provider' => $this->input('provider'),
            'name' => (string) $this->input('name'),
            'sender_id' => $this->input('sender_id'),
            'priority' => (int) $this->input('priority', 10),
            'is_default' => $this->boolean('is_default'),
            'is_active' => $this->boolean('is_active', true),
            'options' => (array) $this->input('options', []),
        ];
    }

    /** @return array<string, mixed> */
    public function credentials(): array
    {
        return (array) $this->input('credentials', []);
    }
}
