<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\Clinic\Enums\Locale;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Models\Concerns\HasPublicId;
use App\Tenancy\TenantResolver;
use Carbon\CarbonImmutable;
use Database\Factories\Central\TenantFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Pennant\Contracts\FeatureScopeable;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string $schema_name
 * @property TenantStatus $status
 * @property string $timezone
 * @property Locale $locale
 * @property string $currency
 * @property string $owner_name
 * @property string $owner_email
 * @property string $owner_mobile
 * @property int|null $current_subscription_id
 * @property CarbonImmutable|null $trial_ends_at
 * @property CarbonImmutable|null $suspended_at
 * @property string|null $suspension_reason
 * @property array<string, mixed> $onboarding
 * @property array<string, mixed> $branding
 * @property CarbonImmutable|null $provisioned_at
 * @property CarbonImmutable|null $last_backup_at
 * @property CarbonImmutable|null $data_export_requested_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Subscription|null $currentSubscription
 * @property-read Collection<int, Domain> $domains
 */
final class Tenant extends CentralModel implements FeatureScopeable
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory, HasPublicId, SoftDeletes;

    protected static string $factory = TenantFactory::class;

    protected $table = 'public.tenants';

    protected $fillable = [
        'name', 'slug', 'status', 'timezone', 'locale', 'currency', 'owner_name', 'owner_email', 'owner_mobile',
        'trial_ends_at', 'suspended_at', 'suspension_reason', 'onboarding', 'branding', 'provisioned_at',
        'last_backup_at', 'data_export_requested_at',
    ];

    protected static function booted(): void
    {
        $forget = function (Tenant $tenant): void {
            app(TenantResolver::class)->forget($tenant->slug.'.'.config('tenancy.central_domain'));

            if ($tenant->isDirty('slug') && $tenant->getOriginal('slug') !== null) {
                app(TenantResolver::class)->forget($tenant->getOriginal('slug').'.'.config('tenancy.central_domain'));
            }
        };

        self::saved($forget);
        self::deleted($forget);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => TenantStatus::class,
            'locale' => Locale::class,
            'onboarding' => 'array',
            'branding' => 'array',
            'trial_ends_at' => 'immutable_datetime',
            'suspended_at' => 'immutable_datetime',
            'provisioned_at' => 'immutable_datetime',
            'last_backup_at' => 'immutable_datetime',
            'data_export_requested_at' => 'immutable_datetime',
        ];
    }

    public function toFeatureIdentifier(string $driver): string
    {
        return "tenant:{$this->id}";
    }

    /** @return HasMany<Domain, $this> */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /** @return BelongsTo<Subscription, $this> */
    public function currentSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'current_subscription_id');
    }

    /** @return HasMany<UsageCounter, $this> */
    public function usageCounters(): HasMany
    {
        return $this->hasMany(UsageCounter::class);
    }

    /** @return HasMany<TenantBackup, $this> */
    public function backups(): HasMany
    {
        return $this->hasMany(TenantBackup::class);
    }

    /** @return HasMany<SubscriptionInvoice, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(SubscriptionInvoice::class);
    }

    /** @return HasMany<SubscriptionPayment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(SubscriptionPayment::class);
    }

    /** @return HasMany<CatalogReconciliationReport, $this> */
    public function reconciliationReports(): HasMany
    {
        return $this->hasMany(CatalogReconciliationReport::class);
    }

    /** @return HasMany<CustomBrandPromotion, $this> */
    public function customBrandPromotions(): HasMany
    {
        return $this->hasMany(CustomBrandPromotion::class);
    }

    /** @return HasMany<ImpersonationToken, $this> */
    public function impersonationTokens(): HasMany
    {
        return $this->hasMany(ImpersonationToken::class);
    }

    /** @return HasMany<AuditLogCentral, $this> */
    public function centralAuditLogs(): HasMany
    {
        return $this->hasMany(AuditLogCentral::class);
    }

    /**
     * Servable tenants: trial | active | past_due.
     *
     * @param  Builder<Tenant>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', [TenantStatus::Trial->value, TenantStatus::Active->value, TenantStatus::PastDue->value]);
    }

    public function isServable(): bool
    {
        return in_array($this->status, [TenantStatus::Trial, TenantStatus::Active, TenantStatus::PastDue], true);
    }

    public function primaryHost(): string
    {
        return $this->slug.'.'.config('tenancy.central_domain');
    }
}
