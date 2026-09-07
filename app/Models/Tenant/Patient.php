<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Clinic\Enums\Gender;
use App\Domain\Clinic\Enums\Locale;
use App\Domain\Patients\Enums\BloodGroup;
use App\Domain\Patients\Enums\PatientSource;
use App\Domain\Patients\Services\MobileNumber;
use App\Models\Concerns\HasPublicId;
use App\Models\Tenant\Concerns\AssertsTenantId;
use App\Models\Tenant\Concerns\RequiresTenancy;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PatientFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Scout\Searchable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Patient master (SCHEMA §3.2): identity is the mobile number, a household shares one number (§5.4), the
 * authenticatable for guard `patient` (OTP only — no password, no remember token). Meilisearch index
 * t{tenantId}_patients (§5.6) carries no ENC field and no address.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $patient_code
 * @property string $name
 * @property string $name_normalized
 * @property string $mobile
 * @property bool $is_mobile_owner
 * @property Gender|null $gender
 * @property CarbonImmutable|null $dob
 * @property bool $dob_is_estimated
 * @property BloodGroup|null $blood_group
 * @property string|null $email
 * @property string|null $address
 * @property string|null $district
 * @property string|null $national_id
 * @property string|null $guardian_name
 * @property string|null $photo_path
 * @property Locale $preferred_language
 * @property string|null $notes
 * @property array<int, string> $tags
 * @property int|null $registered_branch_id
 * @property int|null $registered_by_user_id
 * @property PatientSource $source
 * @property bool $is_active
 * @property CarbonImmutable|null $last_visit_at
 * @property int $visit_count
 * @property CarbonImmutable|null $deleted_at
 * @property-read int|null $age_years
 * @property-read int|null $age_months
 * @property-read string|null $age_text
 * @property-read string $mobile_local
 * @property-read Branch|null $registeredBranch
 * @property-read User|null $registeredBy
 * @property-read PatientRelation|null $primaryRelation
 * @property-read Collection<int, PatientRelation> $dependentRelations
 * @property-read Collection<int, Patient> $dependents
 * @property-read Collection<int, PatientAllergy> $allergies
 * @property-read Collection<int, PatientCondition> $conditions
 * @property-read Collection<int, PatientMedication> $medications
 * @property-read Collection<int, PatientDocument> $documents
 * @property-read Collection<int, PatientConsent> $consents
 */
final class Patient extends Authenticatable
{
    /** @use HasFactory<PatientFactory> */
    use AssertsTenantId, Auditable, HasFactory, HasPublicId, HasRoles, Notifiable, RequiresTenancy, Searchable, SoftDeletes;

    protected static string $factory = PatientFactory::class;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = [
        'name', 'mobile', 'is_mobile_owner', 'gender', 'dob', 'dob_is_estimated', 'blood_group', 'email', 'address', 'district',
        'national_id', 'guardian_name', 'photo_path', 'preferred_language', 'notes', 'tags', 'registered_branch_id', 'source',
        'is_active', 'deleted_at',
    ];

    protected $connection = 'pgsql';

    protected $table = 'patients';

    protected $fillable = [
        'tenant_id', 'patient_code', 'name', 'mobile', 'is_mobile_owner', 'gender', 'dob', 'dob_is_estimated', 'blood_group', 'email',
        'address', 'district', 'national_id', 'guardian_name', 'photo_path', 'preferred_language', 'notes', 'tags',
        'registered_branch_id', 'registered_by_user_id', 'source', 'is_active', 'last_visit_at', 'visit_count',
    ];

    protected $hidden = ['national_id', 'notes'];

    protected static function booted(): void
    {
        self::attachTenantAssertion();

        self::creating(function (self $patient): void {
            if (blank($patient->getAttribute('patient_code'))) {
                $patient->setAttribute('patient_code', self::nextPatientCode());
            }
        });
    }

    /** `P-000123` from the per-schema sequence (SCHEMA §0.4). */
    public static function nextPatientCode(): string
    {
        $next = (int) DB::connection('pgsql')->scalar("select nextval('patient_code_seq')");

        return 'P-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_mobile_owner' => 'boolean',
            'gender' => Gender::class,
            'dob' => 'immutable_date',
            'dob_is_estimated' => 'boolean',
            'blood_group' => BloodGroup::class,
            'national_id' => 'encrypted',
            'preferred_language' => Locale::class,
            'notes' => 'encrypted',
            'tags' => 'array',
            'source' => PatientSource::class,
            'is_active' => 'boolean',
            'last_visit_at' => 'immutable_datetime',
            'visit_count' => 'integer',
        ];
    }

    // ---- auth: OTP only, no password, no remember token (SCHEMA §3.2) --------------------------------------------

    public function getRememberToken(): ?string
    {
        return null;
    }

    /** @param  string  $value */
    public function setRememberToken($value): void
    {
        // patients have no remember_token column; sessions are the only login state
    }

    // ---- search (SCHEMA §5.6) -----------------------------------------------------------------------------------

    public function searchableAs(): string
    {
        return config('scout.prefix').'t'.Tenancy::id().'_patients';
    }

    public function shouldBeSearchable(): bool
    {
        return ! $this->trashed();
    }

    /**
     * No ENC field, no address.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'mobile_local' => $this->mobile_local,
            'patient_code' => $this->patient_code,
            'gender' => $this->gender?->value,
            'registered_branch_id' => $this->registered_branch_id,
            'is_active' => $this->is_active,
            'age_text' => $this->age_text,
            'last_visit_at' => $this->last_visit_at?->getTimestamp(),
        ];
    }

    // ---- accessors ------------------------------------------------------------------------------------------------

    public function getMobileLocalAttribute(): string
    {
        return MobileNumber::toLocal($this->mobile);
    }

    public function getAgeYearsAttribute(): ?int
    {
        return $this->dob === null ? null : (int) $this->dob->diffInYears(Clock::today());
    }

    public function getAgeMonthsAttribute(): ?int
    {
        return $this->dob === null ? null : (int) $this->dob->diffInMonths(Clock::today());
    }

    /** "34y", "8m", "3y 2m" for under-fives, or null when dob is unknown. */
    public function getAgeTextAttribute(): ?string
    {
        if ($this->dob === null) {
            return null;
        }

        $years = $this->age_years ?? 0;
        $months = ($this->age_months ?? 0) % 12;

        if ($years === 0) {
            return "{$months}m";
        }

        if ($years < 5 && $months > 0) {
            return "{$years}y {$months}m";
        }

        return "{$years}y";
    }

    // ---- relations ------------------------------------------------------------------------------------------------

    /** @return BelongsTo<Branch, $this> */
    public function registeredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'registered_branch_id');
    }

    /** @return BelongsTo<User, $this> */
    public function registeredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by_user_id');
    }

    /**
     * The row that makes this patient a dependent of a primary (at most one).
     *
     * @return HasOne<PatientRelation, $this>
     */
    public function primaryRelation(): HasOne
    {
        return $this->hasOne(PatientRelation::class, 'dependent_patient_id');
    }

    /** @return HasMany<PatientRelation, $this> */
    public function dependentRelations(): HasMany
    {
        return $this->hasMany(PatientRelation::class, 'primary_patient_id');
    }

    /** @return BelongsToMany<Patient, $this> */
    public function dependents(): BelongsToMany
    {
        return $this->belongsToMany(self::class, 'patient_relations', 'primary_patient_id', 'dependent_patient_id')
            ->withPivot('relation')
            ->withTimestamps();
    }

    /** @return HasMany<PatientAllergy, $this> */
    public function allergies(): HasMany
    {
        return $this->hasMany(PatientAllergy::class);
    }

    /** @return HasMany<PatientCondition, $this> */
    public function conditions(): HasMany
    {
        return $this->hasMany(PatientCondition::class);
    }

    /** @return HasMany<PatientMedication, $this> */
    public function medications(): HasMany
    {
        return $this->hasMany(PatientMedication::class);
    }

    /** @return HasMany<PatientDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(PatientDocument::class);
    }

    /** @return HasMany<PatientConsent, $this> */
    public function consents(): HasMany
    {
        return $this->hasMany(PatientConsent::class);
    }

    /** @return HasMany<PatientOtpCode, $this> */
    public function otpCodes(): HasMany
    {
        return $this->hasMany(PatientOtpCode::class);
    }

    // ---- scopes ---------------------------------------------------------------------------------------------------

    /** @param  Builder<Patient>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Everyone registered on one E.164 mobile, owner first.
     *
     * @param  Builder<Patient>  $query
     */
    public function scopeHousehold(Builder $query, string $mobile): void
    {
        $query->where('mobile', $mobile)->orderByDesc('is_mobile_owner')->orderBy('id');
    }
}
