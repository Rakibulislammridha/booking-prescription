<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Support\SettingsRegistry;
use App\Models\Tenant\Setting;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Value typing is done by SettingsRegistry::validate() inside the Settings service (DomainException on failure).
 */
final class UpdateSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Setting::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', Rule::in(array_keys(SettingsRegistry::all()))],
            'value' => ['present'],
        ];
    }

    public function key(): string
    {
        return (string) $this->validated('key');
    }

    public function value(): mixed
    {
        return $this->validated('value');
    }
}
