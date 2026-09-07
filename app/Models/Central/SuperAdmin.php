<?php

declare(strict_types=1);

namespace App\Models\Central;

use Carbon\CarbonImmutable;
use Database\Factories\Central\SuperAdminFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use LogicException;

/**
 * Platform operators — guard `super`. Not a Spatie role.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property bool $is_active
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $last_login_ip
 * @property-read Collection<int, ImpersonationToken> $impersonationTokens
 * @property-read Collection<int, AuditLogCentral> $auditLogs
 */
final class SuperAdmin extends Authenticatable
{
    /** @use HasFactory<SuperAdminFactory> */
    use HasApiTokens, HasFactory, Notifiable, SoftDeletes;

    protected static string $factory = SuperAdminFactory::class;

    protected $connection = 'pgsql';

    protected $table = 'public.super_admins';

    protected $fillable = ['name', 'email', 'password', 'is_active', 'email_verified_at', 'last_login_at', 'last_login_ip'];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected static function booted(): void
    {
        str_starts_with((new self)->getTable(), 'public.') || throw new LogicException(self::class.' must set $table = "public.…"');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'email_verified_at' => 'immutable_datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'last_login_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<ImpersonationToken, $this> */
    public function impersonationTokens(): HasMany
    {
        return $this->hasMany(ImpersonationToken::class);
    }

    /** @return HasMany<AuditLogCentral, $this> */
    public function auditLogs(): HasMany
    {
        return $this->hasMany(AuditLogCentral::class);
    }

    /** @return HasMany<CustomBrandPromotion, $this> */
    public function reviewedPromotions(): HasMany
    {
        return $this->hasMany(CustomBrandPromotion::class, 'reviewed_by_super_admin_id');
    }

    /** @return HasMany<CatalogReconciliationReport, $this> */
    public function resolvedReconciliationReports(): HasMany
    {
        return $this->hasMany(CatalogReconciliationReport::class, 'resolved_by_super_admin_id');
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function recordedPayments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class, 'recorded_by_super_admin_id');
    }
}
