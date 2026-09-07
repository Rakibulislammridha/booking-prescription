<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Notifications;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Models\Tenant\NotificationTemplate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', NotificationTemplate::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'event_key' => ['required', Rule::enum(NotificationEvent::class)],
            'channel' => ['required', Rule::enum(NotificationChannel::class)],
            'locale' => ['required', Rule::enum(Locale::class)],
            'subject' => ['nullable', 'string', 'max:160'],
            'body' => ['required', 'string', 'max:2000'],
            'provider_template_id' => ['nullable', 'string', 'max:64'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function event(): NotificationEvent
    {
        return NotificationEvent::from((string) $this->input('event_key'));
    }

    public function channel(): NotificationChannel
    {
        return NotificationChannel::from((string) $this->input('channel'));
    }

    public function locale(): Locale
    {
        return Locale::from((string) $this->input('locale'));
    }
}
