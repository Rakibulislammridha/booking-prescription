<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\BackupEncryption;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<TenantBackup> */
final class TenantBackupFactory extends Factory
{
    protected $model = TenantBackup::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'type' => BackupType::Daily,
            'status' => BackupStatus::Pending,
            'storage_disk' => 'backups',
        ];
    }

    public function completed(): static
    {
        return $this->state(fn () => [
            'status' => BackupStatus::Completed,
            'storage_path' => 'tenants/1/'.now()->format('Ymd').'.dump.enc',
            'encryption' => BackupEncryption::XChaCha20Poly1305,
            'size_bytes' => 1024,
            'checksum_sha256' => hash('sha256', 'x'),
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'expires_at' => now()->addDays(30),
        ]);
    }
}
