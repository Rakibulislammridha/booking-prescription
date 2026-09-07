<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Audit\Concerns\Auditable;
use App\Models\Concerns\HasPublicId;
use App\Models\Tenant\Concerns\AssertsTenantId;
use App\Models\Tenant\Concerns\RequiresTenancy;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant-schema models: bare table names resolved by the tenant-only search path (ARCHITECTURE §5.1).
 * Auditable is opt-in via static::$audited; HasPublicId acts only when static::$publicId is true;
 * TenantAssertionScope applies where SCHEMA §5.9 lists a tenant_id column (static::$assertsTenantId).
 */
abstract class TenantModel extends Model
{
    use AssertsTenantId, Auditable, HasPublicId, RequiresTenancy;

    protected $connection = 'pgsql';

    protected static bool $audited = false;

    protected static bool $assertsTenantId = false;

    protected static bool $publicId = false;

    protected static function boot(): void
    {
        parent::boot();
        static::bootTenantModel();
    }

    public static function bootTenantModel(): void
    {
        if (static::$assertsTenantId) {
            static::attachTenantAssertion();
        }
    }

    public function usesPublicId(): bool
    {
        return static::$publicId;
    }

    public function searchableAs(): string
    {
        return config('scout.prefix').'t'.Tenancy::id().'_'.parent::getTable();
    }
}
