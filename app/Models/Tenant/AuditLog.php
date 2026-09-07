<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Append-only audit trail (SCHEMA §3.7). No update()/delete() path exists on purpose.
 *
 * @property int $id
 * @property int $tenant_id
 * @property AuditActorType $actor_type
 * @property int|null $actor_id
 * @property int|null $impersonator_super_admin_id
 * @property AuditAction $action
 * @property string $auditable_type
 * @property int $auditable_id
 * @property int|null $patient_id
 * @property array<string, mixed>|null $before
 * @property array<string, mixed>|null $after
 * @property array<string, mixed> $context
 * @property string|null $ip
 * @property string|null $user_agent
 * @property string|null $request_id
 * @property CarbonImmutable $occurred_at
 */
final class AuditLog extends TenantModel
{
    /** @use HasFactory<AuditLogFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static string $factory = AuditLogFactory::class;

    protected static bool $assertsTenantId = true;

    protected $table = 'audit_logs';

    protected $fillable = [
        'actor_type', 'actor_id', 'impersonator_super_admin_id', 'action', 'auditable_type', 'auditable_id', 'patient_id',
        'before', 'after', 'context', 'ip', 'user_agent', 'request_id', 'occurred_at',
    ];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('audit_logs is append-only.'));
        self::deleting(fn () => throw new LogicException('audit_logs is append-only.'));
    }

    /**
     * Explicit read audit — every clinical show/print/export/download calls this (ARCHITECTURE §8.1).
     *
     * @param  array<string, mixed>  $context
     */
    public static function view(Model $subject, array $context = []): self
    {
        return app(AuditRecorder::class)->view($subject, $context);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'actor_type' => AuditActorType::class,
            'action' => AuditAction::class,
            'before' => 'array',
            'after' => 'array',
            'context' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return MorphTo<Model, $this> */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
