<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Notifications\Enums\NotificationLogStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\NotificationLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per delivery attempt with the sanitised provider exchange (SCHEMA §3.6). `request` NEVER carries a
 * credential — SanitisedPayload strips them before the row is written. created_at only.
 *
 * @property int $id
 * @property int $notification_id
 * @property int $attempt_no
 * @property string $provider
 * @property string|null $provider_message_id
 * @property NotificationLogStatus $status
 * @property array<string, mixed>|null $request
 * @property array<string, mixed>|null $response
 * @property string|null $error_code
 * @property int|null $latency_ms
 * @property CarbonImmutable $created_at
 * @property-read Notification $notification
 */
final class NotificationLog extends TenantModel
{
    /** @use HasFactory<NotificationLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static string $factory = NotificationLogFactory::class;

    protected $table = 'notification_logs';

    protected $fillable = ['notification_id', 'attempt_no', 'provider', 'provider_message_id', 'status', 'request', 'response', 'error_code', 'latency_ms'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => NotificationLogStatus::class,
            'request' => 'array',
            'response' => 'array',
            'attempt_no' => 'integer',
            'latency_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Notification, $this> */
    public function notification(): BelongsTo
    {
        return $this->belongsTo(Notification::class);
    }
}
