<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\NotificationTemplateFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant, per-event, per-channel, per-locale message body (SCHEMA §3.6). A missing (or inactive) row falls
 * back to `App\Domain\Notifications\Services\DefaultTemplates`, so a clinic that never opens the screen still
 * sends sensible Bangla.
 *
 * @property int $id
 * @property NotificationEvent $event_key
 * @property NotificationChannel $channel
 * @property Locale $locale
 * @property string|null $subject
 * @property string $body
 * @property string|null $provider_template_id
 * @property bool $is_active
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $updatedBy
 */
final class NotificationTemplate extends TenantModel
{
    /** @use HasFactory<NotificationTemplateFactory> */
    use HasFactory;

    protected static string $factory = NotificationTemplateFactory::class;

    protected static bool $audited = true;

    protected $table = 'notification_templates';

    protected $fillable = ['event_key', 'channel', 'locale', 'subject', 'body', 'provider_template_id', 'is_active', 'updated_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_key' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
            'locale' => Locale::class,
            'is_active' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
