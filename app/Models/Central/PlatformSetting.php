<?php

declare(strict_types=1);

namespace App\Models\Central;

use Carbon\CarbonImmutable;
use Database\Factories\Central\PlatformSettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Platform-wide key/value configuration (SCHEMA §2.19); keys are the closed registry
 * `App\Domain\SaaS\Support\PlatformSettingsRegistry`. Read through `App\Domain\SaaS\Services\PlatformSettings`,
 * never directly — the service owns the cache, the defaults and the secret handling.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by_super_admin_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read SuperAdmin|null $updatedBy
 */
final class PlatformSetting extends CentralModel
{
    /** @use HasFactory<PlatformSettingFactory> */
    use HasFactory;

    protected static string $factory = PlatformSettingFactory::class;

    protected $table = 'public.platform_settings';

    protected $fillable = ['key', 'value', 'updated_by_super_admin_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** @return BelongsTo<SuperAdmin, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(SuperAdmin::class, 'updated_by_super_admin_id');
    }
}
