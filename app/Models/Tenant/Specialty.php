<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Database\Factories\Tenant\SpecialtyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property string|null $icon
 * @property int $sort_order
 * @property bool $is_active
 */
final class Specialty extends TenantModel
{
    /** @use HasFactory<SpecialtyFactory> */
    use HasFactory;

    protected static string $factory = SpecialtyFactory::class;

    protected $table = 'specialties';

    protected $fillable = ['name', 'name_bn', 'slug', 'icon', 'sort_order', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsToMany<Doctor, $this> */
    public function doctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_specialties')->withPivot('is_primary');
    }

    /** @return HasMany<DoctorSpecialty, $this> */
    public function doctorSpecialties(): HasMany
    {
        return $this->hasMany(DoctorSpecialty::class);
    }
}
