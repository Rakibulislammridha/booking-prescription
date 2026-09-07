<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Notifications;

use App\Domain\Patients\Services\MobileNumber;
use App\Models\Tenant\SmsGatewaySetting;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

final class TestGatewayRequest extends FormRequest
{
    public function authorize(): bool
    {
        $gateway = $this->route('gateway');

        return $gateway instanceof SmsGatewaySetting && ($this->user('web')?->can('test', $gateway) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'recipient' => ['required', 'string', 'max:255', fn (string $attr, mixed $value, Closure $fail) => MobileNumber::isValid((string) $value) || $fail(__('notifications.validation.recipient'))],
            'body' => ['required', 'string', 'max:1000'],
        ];
    }
}
