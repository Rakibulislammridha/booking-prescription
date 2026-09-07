<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\Reception;

use App\Domain\Reception\Enums\DeviceKind;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /api/reception/devices/register (OFFLINE §2.1) — staff session, permission reception.devices.register. */
final class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User && $user->can('register', ReceptionDevice::class);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:80'],
            'branch' => ['required', 'string', 'size:26'],
            'kind' => ['sometimes', Rule::enum(DeviceKind::class)],
            'device_fingerprint' => ['required', 'string', 'min:16', 'max:128'],
            'app_version' => ['nullable', 'string', 'max:20'],
            'block_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
