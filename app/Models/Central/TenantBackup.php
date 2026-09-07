<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use Database\Factories\Central\TenantBackupFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property BackupType $type
 * @property BackupStatus $status
 * @property string $storage_disk
 * @property string|null $storage_path
 */
final class TenantBackup extends CentralModel
{
    /** @use HasFactory<TenantBackupFactory> */
    use HasFactory;

    protected static string $factory = TenantBackupFactory::class;

    protected $table = 'public.tenant_backups';

    protected $fillable = [
        'tenant_id', 'type', 'status', 'storage_disk', 'storage_path', 'size_bytes', 'checksum_sha256', 'started_at',
        'completed_at', 'expires_at', 'error', 'requested_by_super_admin_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => BackupType::class,
            'status' => BackupStatus::class,
            'size_bytes' => 'integer',
            'started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'requested_by_super_admin_id');
    }
}
