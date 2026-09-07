<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Database\Factories\Tenant\DepartmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property int $sort_order
 * @property bool $is_active
 */
final class Department extends TenantModel
{
    /** @use HasFactory<DepartmentFactory> */
    use HasFactory;

    protected static string $factory = DepartmentFactory::class;

    protected $table = 'departments';

    protected $fillable = ['branch_id', 'name', 'name_bn', 'slug', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return HasMany<Doctor, $this> */
    public function doctors(): HasMany
    {
        return $this->hasMany(Doctor::class);
    }
}
