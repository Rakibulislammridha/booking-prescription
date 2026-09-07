<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AuditLogCentral> */
final class AuditLogCentralFactory extends Factory
{
    protected $model = AuditLogCentral::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'super_admin_id' => SuperAdmin::factory(),
            'tenant_id' => null,
            'action' => CentralAuditAction::Login,
            'ip' => $this->faker->ipv4(),
            'user_agent' => 'phpunit',
            'occurred_at' => now(),
        ];
    }
}
