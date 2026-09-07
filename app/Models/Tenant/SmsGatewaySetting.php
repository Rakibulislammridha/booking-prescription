<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\SmsGatewaySettingFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-tenant provider credentials for SMS / WhatsApp / IVR (SCHEMA §3.6). The class name is SCHEMA decision 15's
 * even though the table serves three channels. `credentials` is `encrypted:array`, is `$hidden`, and is logged as
 * "[encrypted]" by AuditRecorder::redact — it must never reach a JSON resource, a log line or a provider response row.
 *
 * @property int $id
 * @property NotificationChannel $channel
 * @property GatewayProvider $provider
 * @property string $name
 * @property string|null $sender_id
 * @property array<string, mixed> $credentials
 * @property array<string, mixed> $options
 * @property int $priority
 * @property bool $is_default
 * @property bool $is_active
 * @property int|null $balance_paisa
 * @property CarbonImmutable|null $balance_checked_at
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read User|null $updatedBy
 */
final class SmsGatewaySetting extends TenantModel
{
    /** @use HasFactory<SmsGatewaySettingFactory> */
    use HasFactory;

    protected static string $factory = SmsGatewaySettingFactory::class;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = ['channel', 'provider', 'name', 'sender_id', 'credentials', 'options', 'priority', 'is_default', 'is_active'];

    protected $table = 'sms_gateway_settings';

    protected $fillable = [
        'channel', 'provider', 'name', 'sender_id', 'credentials', 'options', 'priority', 'is_default', 'is_active',
        'balance_paisa', 'balance_checked_at', 'updated_by_user_id',
    ];

    protected $hidden = ['credentials'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'channel' => NotificationChannel::class,
            'provider' => GatewayProvider::class,
            'credentials' => 'encrypted:array',
            'options' => 'array',
            'priority' => 'integer',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'balance_paisa' => 'integer',
            'balance_checked_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<$this>  $query */
    public function scopeUsable(Builder $query, NotificationChannel $channel): void
    {
        $query->where('channel', $channel->value)->where('is_active', true)->orderByDesc('is_default')->orderBy('priority')->orderBy('id');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
