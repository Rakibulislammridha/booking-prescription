<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Reception\Enums\DeviceKind;
use App\Domain\Reception\Enums\DeviceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\ReceptionDeviceFactory;
use Illuminate\Auth\Authenticatable as AuthenticatableTrait;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Laravel\Sanctum\HasApiTokens;

/**
 * A registered reception tablet or display box (SCHEMA §3.3) — the Sanctum tokenable of guard `device`
 * (OFFLINE §2). Tokens live in the tenant personal_access_tokens (Tenancy swaps Sanctum's token model). Authenticatable
 * so the `device` guard / provider can hold it; it has no password or remember token (identity is the token).
 *
 * @property int $id
 * @property string $public_id
 * @property int $branch_id
 * @property int $number
 * @property string $name
 * @property DeviceKind $kind
 * @property string $device_fingerprint
 * @property string|null $device_secret_hash
 * @property string|null $app_version
 * @property int|null $registered_by_user_id
 * @property DeviceStatus $status
 * @property int $block_size
 * @property CarbonImmutable|null $last_seen_at
 * @property CarbonImmutable|null $last_sync_at
 * @property string|null $last_ip
 * @property string|null $user_agent
 * @property CarbonImmutable|null $revoked_at
 * @property-read Branch $branch
 * @property-read User|null $registeredBy
 */
final class ReceptionDevice extends TenantModel implements Authenticatable
{
    /** @use HasFactory<ReceptionDeviceFactory> */
    use AuthenticatableTrait, HasApiTokens, HasFactory;

    public const ABILITIES = ['reception:offline', 'reception:sync', 'reception:blocks', 'reception:read'];

    public const TOKEN_DAYS = 90;

    protected static string $factory = ReceptionDeviceFactory::class;

    protected static bool $publicId = true;

    protected $table = 'reception_devices';

    protected $fillable = [
        'branch_id', 'number', 'name', 'kind', 'device_fingerprint', 'app_version', 'registered_by_user_id', 'status', 'block_size',
        'last_seen_at', 'last_sync_at', 'last_ip', 'user_agent', 'revoked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'kind' => DeviceKind::class,
            'status' => DeviceStatus::class,
            'block_size' => 'integer',
            'last_seen_at' => 'immutable_datetime',
            'last_sync_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    /** @return HasMany<SerialBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(SerialBlock::class, 'reception_device_id');
    }

    /** @return HasMany<OfflineEvent, $this> */
    public function offlineEvents(): HasMany
    {
        return $this->hasMany(OfflineEvent::class, 'reception_device_id');
    }

    /** @param  Builder<ReceptionDevice>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', DeviceStatus::Active->value);
    }

    public function isActive(): bool
    {
        return $this->status === DeviceStatus::Active;
    }

    /** `D2-000123` — the offline receipt prefix (OFFLINE §6.1). */
    public function receiptPrefix(): string
    {
        return 'D'.$this->number.'-';
    }
}
