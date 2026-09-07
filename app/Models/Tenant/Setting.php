<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Database\Factories\Tenant\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tenant key/value configuration (non-secret); keys are the closed registry of SCHEMA Appendix B.
 * Read through App\Domain\Clinic\Services\Settings, never directly.
 *
 * @property int $id
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by_user_id
 * @property-read User|null $updatedBy
 */
final class Setting extends TenantModel
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory;

    protected static string $factory = SettingFactory::class;

    protected $table = 'settings';

    protected $fillable = ['key', 'value', 'updated_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    /** @return BelongsTo<User, $this> */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_user_id');
    }
}
