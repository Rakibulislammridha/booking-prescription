<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Notifications;

use Illuminate\Foundation\Http\FormRequest;

final class SubscribeToPushRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null || $this->user('patient') !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:2000'],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'min:16', 'max:255'],
            'keys.auth' => ['required', 'string', 'min:8', 'max:64'],
            'content_encoding' => ['sometimes', 'string', 'in:aes128gcm,aesgcm'],
        ];
    }
}
