<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\BrandingData;
use App\Models\Tenant\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Clinic identity for the public site (SCHEMA §2.1 `tenants.branding`). Colours must be hex — HandleInertiaRequests
 * interpolates them straight into `--tenant-*` CSS variables and silently drops anything that is not.
 */
final class UpdateBrandingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Setting::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'];

        return [
            'name' => ['required', 'string', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:200'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'primary_color' => $hex,
            'accent_color' => $hex,
            'on_primary_color' => $hex,
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'clear_logo' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): BrandingData
    {
        return BrandingData::fromRequest($this);
    }
}
