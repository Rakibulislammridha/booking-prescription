<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Database\Factories\Tenant\DoctorSpecialtyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $doctor_id
 * @property int $specialty_id
 * @property bool $is_primary
 */
final class DoctorSpecialty extends TenantModel
{
    /** @use HasFactory<DoctorSpecialtyFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    protected static string $factory = DoctorSpecialtyFactory::class;

    protected $table = 'doctor_specialties';

    protected $fillable = ['doctor_id', 'specialty_id', 'is_primary'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<Specialty, $this> */
    public function specialty(): BelongsTo
    {
        return $this->belongsTo(Specialty::class);
    }
}
