<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Clinic\Enums\Gender;
use Database\Factories\Tenant\DoctorFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * @property int $id
 * @property string $public_id
 * @property int|null $user_id
 * @property string $name
 * @property string|null $name_bn
 * @property string $slug
 * @property string $code
 * @property Gender|null $gender
 * @property string|null $mobile
 * @property string|null $email
 * @property int|null $department_id
 * @property string|null $photo_path
 * @property bool $is_active
 * @property bool $accepts_online_booking
 * @property bool $accepts_telemedicine
 * @property int $sort_order
 * @property string|null $room_label
 * @property-read DoctorProfile|null $profile
 * @property-read DoctorPadSetting|null $padSetting
 * @property-read User|null $user
 */
final class Doctor extends TenantModel
{
    /** @use HasFactory<DoctorFactory> */
    use HasFactory, SoftDeletes;

    protected static string $factory = DoctorFactory::class;

    protected static bool $publicId = true;

    protected $table = 'doctors';

    protected $fillable = [
        'user_id', 'name', 'name_bn', 'slug', 'code', 'gender', 'mobile', 'email', 'department_id', 'photo_path',
        'is_active', 'accepts_online_booking', 'accepts_telemedicine', 'sort_order', 'room_label',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'gender' => Gender::class,
            'is_active' => 'boolean',
            'accepts_online_booking' => 'boolean',
            'accepts_telemedicine' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Department, $this> */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    /** @return HasOne<DoctorProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(DoctorProfile::class);
    }

    /** @return HasOne<DoctorPadSetting, $this> */
    public function padSetting(): HasOne
    {
        return $this->hasOne(DoctorPadSetting::class);
    }

    /** @return BelongsToMany<Specialty, $this> */
    public function specialties(): BelongsToMany
    {
        return $this->belongsToMany(Specialty::class, 'doctor_specialties')->withPivot('is_primary');
    }

    /** @return HasMany<DoctorSpecialty, $this> */
    public function doctorSpecialties(): HasMany
    {
        return $this->hasMany(DoctorSpecialty::class);
    }

    /** @return HasMany<DoctorLeave, $this> */
    public function leaves(): HasMany
    {
        return $this->hasMany(DoctorLeave::class);
    }

    /** @param  Builder<Doctor>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
