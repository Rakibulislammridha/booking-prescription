<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Notifications;

use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** `POST notifications/templates/preview {template, locale, subject?, body?}` — render with sample data, nothing saved. */
final class PreviewTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'template' => ['required', Rule::in(PlatformSettingsRegistry::TEMPLATES)],
            'locale' => ['required', Rule::in(['en', 'bn'])],
            'subject' => ['nullable', 'string', 'max:200'],
            'body' => ['nullable', 'string', 'max:4000'],
        ];
    }
}
