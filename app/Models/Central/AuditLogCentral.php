<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\Audit\Enums\CentralAuditAction;
use Database\Factories\Central\AuditLogCentralFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Super-admin actions; append-only, no timestamps (occurred_at).
 *
 * @property int $id
 * @property int|null $super_admin_id
 * @property int|null $tenant_id
 * @property CentralAuditAction $action
 * @property string|null $auditable_type
 * @property int|null $auditable_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 */
final class AuditLogCentral extends CentralModel
{
    /** @use HasFactory<AuditLogCentralFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static string $factory = AuditLogCentralFactory::class;

    protected $table = 'public.audit_logs_central';

    protected $fillable = [
        'super_admin_id', 'tenant_id', 'action', 'auditable_type', 'auditable_id', 'before', 'after', 'ip', 'user_agent', 'request_id', 'occurred_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'action' => CentralAuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
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
}
