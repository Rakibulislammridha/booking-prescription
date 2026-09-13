<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Clinic\Enums\Locale;
use App\Models\Central\Tenant;
use App\Models\Concerns\HasPublicId;
use App\Models\Tenant\Concerns\AssertsTenantId;
use App\Models\Tenant\Concerns\RequiresTenancy;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\UserFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

/**
 * Staff accounts (guard `web`). Patients are a separate guard/model.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $name
 * @property string $email
 * @property string|null $mobile
 * @property string $password
 * @property int|null $default_branch_id
 * @property Locale $locale
 * @property string|null $avatar_path
 * @property bool $is_active
 * @property bool $must_change_password
 * @property CarbonImmutable|null $last_login_at
 * @property string|null $last_login_ip
 * @property int|null $session_timeout_minutes
 * @property-read Branch|null $defaultBranch
 * @property-read Doctor|null $doctor
 * @property-read Tenant $tenant
 * @property-read Collection<int, Doctor> $assignedDoctors
 */
final class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use AssertsTenantId, Auditable, HasApiTokens, HasFactory, HasPublicId, HasRoles, Notifiable, RequiresTenancy, SoftDeletes;

    protected static string $factory = UserFactory::class;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = ['name', 'email', 'mobile', 'default_branch_id', 'locale', 'is_active', 'must_change_password', 'session_timeout_minutes', 'deleted_at'];

    protected $connection = 'pgsql';

    protected $table = 'users';

    protected $fillable = [
        'tenant_id', 'name', 'email', 'mobile', 'password', 'email_verified_at', 'default_branch_id', 'locale', 'avatar_path',
        'is_active', 'must_change_password', 'session_timeout_minutes', 'last_login_at', 'last_login_ip',
    ];

    protected $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected static function booted(): void
    {
        self::attachTenantAssertion();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'locale' => Locale::class,
            'email_verified_at' => 'immutable_datetime',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'immutable_datetime',
            'last_login_at' => 'immutable_datetime',
            'session_timeout_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<Tenant, $this> the central row this schema belongs to (public.tenants) */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function defaultBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'default_branch_id');
    }

    /** @return HasOne<Doctor, $this> */
    public function doctor(): HasOne
    {
        return $this->hasOne(Doctor::class);
    }

    /**
     * The doctors this user may act for as a compounder. Empty for everyone else — the relation is only consulted
     * for a user holding the `compounder` role, through DoctorScope. Soft-deleted doctors fall out by themselves.
     *
     * @return BelongsToMany<Doctor, $this>
     */
    public function assignedDoctors(): BelongsToMany
    {
        return $this->belongsToMany(Doctor::class, 'doctor_compounder')
            ->withPivot('assigned_by_user_id')
            ->withTimestamps();
    }
}
