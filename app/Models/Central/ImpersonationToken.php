<?php

declare(strict_types=1);

namespace App\Models\Central;

use Carbon\CarbonImmutable;
use Database\Factories\Central\ImpersonationTokenFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Single-use 60 s handoff tokens minted by a super admin (SCHEMA §2.17). created_at only.
 *
 * @property int $id
 * @property int $super_admin_id
 * @property int $tenant_id
 * @property int $user_id
 * @property string $token_hash
 * @property CarbonImmutable $expires_at
 * @property CarbonImmutable|null $consumed_at
 */
final class ImpersonationToken extends CentralModel
{
    /** @use HasFactory<ImpersonationTokenFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static string $factory = ImpersonationTokenFactory::class;

    protected $table = 'public.impersonation_tokens';

    protected $fillable = ['super_admin_id', 'tenant_id', 'user_id', 'token_hash', 'expires_at', 'consumed_at', 'ip'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['expires_at' => 'immutable_datetime', 'consumed_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function superAdmin(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class);
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function isUsable(): bool
    {
        return $this->consumed_at === null && $this->expires_at->isFuture();
    }
}
