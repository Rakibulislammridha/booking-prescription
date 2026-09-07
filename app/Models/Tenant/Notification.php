<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\NotificationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The outbound ledger — one row per recipient per channel per event (SCHEMA §3.6). NOT Laravel's
 * `DatabaseNotification`: this table is the send queue, the audit trail and the billing evidence at once.
 *
 * @property int $id
 * @property NotificationEvent $event_key
 * @property NotificationChannel $channel
 * @property int|null $patient_id
 * @property int|null $user_id
 * @property string|null $notifiable_type
 * @property int|null $notifiable_id
 * @property int|null $serial_id
 * @property int|null $notification_template_id
 * @property string $recipient
 * @property Locale $locale
 * @property string|null $subject
 * @property string $body
 * @property array<string, mixed> $payload
 * @property NotificationStatus $status
 * @property CarbonImmutable|null $scheduled_for
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $delivered_at
 * @property int $attempts
 * @property string|null $last_error
 * @property string|null $dedupe_key
 * @property int|null $segments
 * @property int|null $cost_paisa
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Patient|null $patient
 * @property-read User|null $user
 * @property-read Serial|null $serial
 * @property-read NotificationTemplate|null $template
 * @property-read Collection<int, NotificationLog> $logs
 */
final class Notification extends TenantModel
{
    /** @use HasFactory<NotificationFactory> */
    use HasFactory;

    protected static string $factory = NotificationFactory::class;

    protected $table = 'notifications';

    protected $fillable = [
        'event_key', 'channel', 'patient_id', 'user_id', 'notifiable_type', 'notifiable_id', 'serial_id',
        'notification_template_id', 'recipient', 'locale', 'subject', 'body', 'payload', 'status', 'scheduled_for',
        'sent_at', 'delivered_at', 'attempts', 'last_error', 'dedupe_key', 'segments', 'cost_paisa',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'event_key' => NotificationEvent::class,
            'channel' => NotificationChannel::class,
            'locale' => Locale::class,
            'status' => NotificationStatus::class,
            'payload' => 'array',
            'scheduled_for' => 'immutable_datetime',
            'sent_at' => 'immutable_datetime',
            'delivered_at' => 'immutable_datetime',
            'attempts' => 'integer',
            'segments' => 'integer',
            'cost_paisa' => 'integer',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Serial, $this> */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(Serial::class);
    }

    /** @return BelongsTo<NotificationTemplate, $this> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(NotificationTemplate::class, 'notification_template_id');
    }

    /** @return MorphTo<Model, $this> */
    public function notifiable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasMany<NotificationLog, $this> */
    public function logs(): HasMany
    {
        return $this->hasMany(NotificationLog::class);
    }

    /** @param  Builder<$this>  $query */
    public function scopeDue(Builder $query, ?CarbonImmutable $at = null): void
    {
        $at ??= CarbonImmutable::now();

        $query->whereIn('status', NotificationStatus::pending())
            ->where(fn (Builder $q) => $q->whereNull('scheduled_for')->orWhere('scheduled_for', '<=', $at));
    }

    /** The dead-letter view: exhausted the retry policy or was refused outright. */
    public function isDeadLettered(): bool
    {
        return $this->status === NotificationStatus::Failed;
    }
}
