<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Audit\Enums\AuditActorType;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Branch;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLog> */
final class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'actor_type' => AuditActorType::System,
            'actor_id' => null,
            'action' => AuditAction::View,
            'auditable_type' => Branch::class,
            'auditable_id' => 1,
            'context' => [],
            'occurred_at' => now(),
        ];
    }
}
