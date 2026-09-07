<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\SaaS\Enums\DomainType;
use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Domain\SaaS\Enums\SslStatus;
use App\Tenancy\TenantResolver;
use Database\Factories\Central\DomainFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property string $domain
 * @property DomainType $type
 * @property bool $is_primary
 * @property DomainVerificationStatus $verification_status
 * @property string $verification_token
 * @property SslStatus $ssl_status
 * @property-read Tenant $tenant
 */
final class Domain extends CentralModel
{
    /** @use HasFactory<DomainFactory> */
    use HasFactory;

    protected static string $factory = DomainFactory::class;

    protected $table = 'public.domains';

    protected $fillable = [
        'tenant_id', 'domain', 'type', 'is_primary', 'verification_status', 'verification_token', 'verified_at',
        'last_checked_at', 'ssl_status', 'ssl_expires_at',
    ];

    protected static function booted(): void
    {
        $forget = function (Domain $domain): void {
            app(TenantResolver::class)->forget($domain->domain);

            if ($domain->isDirty('domain') && $domain->getOriginal('domain') !== null) {
                app(TenantResolver::class)->forget($domain->getOriginal('domain'));
            }
        };

        self::saved($forget);
        self::deleted($forget);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => DomainType::class,
            'is_primary' => 'boolean',
            'verification_status' => DomainVerificationStatus::class,
            'ssl_status' => SslStatus::class,
            'verified_at' => 'immutable_datetime',
            'last_checked_at' => 'immutable_datetime',
            'ssl_expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
