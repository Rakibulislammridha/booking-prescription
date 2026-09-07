<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Reception;

use Illuminate\Foundation\Http\FormRequest;

/** POST /api/reception/blocks/lease {session, size} (OFFLINE §4.1). The device middleware already authorised. */
final class LeaseBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'session' => ['required', 'string', 'size:26'],
            'size' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
