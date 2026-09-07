<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Database\Factories\Tenant\BranchFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $code
 * @property string $slug
 * @property string|null $address
 * @property string|null $phone
 * @property string|null $email
 * @property bool $is_main
 * @property bool $is_active
 * @property array<string, mixed>|null $geo
 * @property array<string, mixed> $settings
 */
final class Branch extends TenantModel
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, SoftDeletes;

    protected static string $factory = BranchFactory::class;

    protected static bool $publicId = true;

    protected $table = 'branches';

    protected $fillable = ['name', 'code', 'slug', 'address', 'phone', 'email', 'is_main', 'is_active', 'geo', 'settings'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'is_active' => 'boolean', 'geo' => 'array', 'settings' => 'array'];
    }

    /** @return HasMany<Department, $this> */
    public function departments(): HasMany
    {
        return $this->hasMany(Department::class);
    }

    /** @return HasMany<Holiday, $this> */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }

    /** @return HasMany<User, $this> staff whose default branch this is */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'default_branch_id');
    }

    /** @return HasMany<DoctorLeave, $this> */
    public function doctorLeaves(): HasMany
    {
        return $this->hasMany(DoctorLeave::class);
    }

    /** @param  Builder<Branch>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
