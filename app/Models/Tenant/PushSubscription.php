<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PushSubscriptionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A Web Push endpoint owned by a staff user or a patient (SCHEMA §3.6). `keys` is ENC; `endpoint_hash` carries the
 * uniqueness. Pruned after `notifications.push.prune_after_failures` consecutive failures.
 *
 * @property int $id
 * @property string $subscriber_type
 * @property int $subscriber_id
 * @property string $endpoint
 * @property string $endpoint_hash
 * @property array<string, string> $keys
 * @property string $content_encoding
 * @property string|null $user_agent
 * @property CarbonImmutable|null $last_used_at
 * @property int $failed_count
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
final class PushSubscription extends TenantModel
{
    /** @use HasFactory<PushSubscriptionFactory> */
    use HasFactory;

    protected static string $factory = PushSubscriptionFactory::class;

    protected $table = 'push_subscriptions';

    protected $fillable = ['subscriber_type', 'subscriber_id', 'endpoint', 'endpoint_hash', 'keys', 'content_encoding', 'user_agent', 'last_used_at', 'failed_count'];

    protected $hidden = ['keys'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'keys' => 'encrypted:array',
            'failed_count' => 'integer',
            'last_used_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public static function hash(string $endpoint): string
    {
        return hash('sha256', $endpoint);
    }

    /** @return MorphTo<Model, $this> */
    public function subscriber(): MorphTo
    {
        return $this->morphTo();
    }
}
