<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Enums\PrescriptionLanguage;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Exceptions\ImmutablePrescriptionException;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PrescriptionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A versioned, immutable-once-issued prescription (SCHEMA §3.4, §5.3). `snapshot` is the only render source once
 * issued. The model guard (PRESCRIPTION.md §6.5) mirrors the DB trigger and throws a typed exception before the
 * round trip; child models refuse writes unless this row is a draft. Audit rows are written by PrescriptionAuditor.
 *
 * @property int $id
 * @property string $public_id
 * @property int $visit_id
 * @property int $patient_id
 * @property int $doctor_id
 * @property int $branch_id
 * @property int $tenant_id
 * @property int $version
 * @property int|null $root_prescription_id
 * @property int|null $supersedes_prescription_id
 * @property PrescriptionStatus $status
 * @property PrescriptionLanguage $language
 * @property CarbonImmutable|null $issued_at
 * @property int|null $issued_by_user_id
 * @property PrescriptionSnapshot|null $snapshot
 * @property string|null $snapshot_sha256
 * @property array<string, mixed>|null $pad_snapshot
 * @property string|null $verification_code
 * @property string|null $handwriting_image_path
 * @property array<string, mixed>|null $drawing_json
 * @property string|null $drawing_image_path
 * @property string|null $pdf_path
 * @property CarbonImmutable|null $pdf_generated_at
 * @property string|null $amend_reason
 * @property CarbonImmutable|null $voided_at
 * @property int|null $voided_by_user_id
 * @property string|null $void_reason
 * @property int $printed_count
 * @property CarbonImmutable|null $last_printed_at
 * @property array<int, string> $delivered_channels
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Visit $visit
 * @property-read Patient $patient
 * @property-read Doctor $doctor
 * @property-read Branch $branch
 * @property-read Prescription|null $supersedes
 * @property-read Prescription|null $supersededBy
 * @property-read Prescription|null $root
 * @property-read Collection<int, PrescriptionItem> $items
 * @property-read Collection<int, PrescriptionInvestigation> $investigations
 * @property-read Collection<int, PrescriptionAdvice> $advice
 * @property-read Collection<int, PrescriptionReferral> $referrals
 */
final class Prescription extends TenantModel
{
    /** @use HasFactory<PrescriptionFactory> */
    use HasFactory;

    /** Columns that may change after issue (SCHEMA §5.3.3). */
    public const MUTABLE_AFTER_ISSUE = [
        'status', 'pdf_path', 'pdf_generated_at', 'printed_count', 'last_printed_at', 'delivered_channels',
        'drawing_image_path', 'voided_at', 'voided_by_user_id', 'void_reason', 'updated_at',
    ];

    /** jsonb columns whose text spelling changes on a Postgres round trip (see jsonUnchanged()). */
    private const JSON_COLUMNS = ['snapshot', 'pad_snapshot', 'drawing_json', 'delivered_channels'];

    protected static string $factory = PrescriptionFactory::class;

    protected static bool $publicId = true;

    protected static bool $assertsTenantId = true;

    protected $table = 'prescriptions';

    protected $fillable = [
        'tenant_id', 'visit_id', 'patient_id', 'doctor_id', 'branch_id', 'version', 'root_prescription_id', 'supersedes_prescription_id',
        'status', 'language', 'issued_at', 'issued_by_user_id', 'snapshot', 'snapshot_sha256', 'pad_snapshot', 'verification_code',
        'handwriting_image_path', 'drawing_json', 'drawing_image_path', 'pdf_path', 'pdf_generated_at', 'amend_reason',
        'voided_at', 'voided_by_user_id', 'void_reason', 'printed_count', 'last_printed_at', 'delivered_channels',
    ];

    protected static function booted(): void
    {
        self::updating(function (self $rx): void {
            $original = $rx->getOriginal('status');
            $originalStatus = $original instanceof PrescriptionStatus ? $original->value : (string) $original;

            if ($originalStatus === PrescriptionStatus::Draft->value || $originalStatus === '') {
                return;
            }

            $dirty = array_keys($rx->getDirty());
            $illegal = array_values(array_filter(array_diff($dirty, self::MUTABLE_AFTER_ISSUE), fn (string $key) => ! self::jsonUnchanged($rx, $key)));

            if ($illegal !== []) {
                throw new ImmutablePrescriptionException($rx->id, $originalStatus, 'Changed: '.implode(', ', $illegal).'.');
            }

            if (in_array('status', $dirty, true)) {
                $to = $rx->status->value;
                $allowed = $originalStatus === PrescriptionStatus::Issued->value
                    && in_array($to, [PrescriptionStatus::Amended->value, PrescriptionStatus::Voided->value], true);

                if (! $allowed) {
                    throw new ImmutablePrescriptionException($rx->id, $originalStatus, "Status {$originalStatus} → {$to} is not permitted.");
                }
            }
        });

        self::deleting(function (self $rx): void {
            if (! $rx->isDraft()) {
                throw new ImmutablePrescriptionException($rx->id, $rx->status->value, 'Issued prescriptions are never deleted.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => PrescriptionStatus::class,
            'language' => PrescriptionLanguage::class,
            'issued_at' => 'immutable_datetime',
            'snapshot' => PrescriptionSnapshot::class,
            'pad_snapshot' => 'array',
            'drawing_json' => 'array',
            'pdf_generated_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
            'printed_count' => 'integer',
            'last_printed_at' => 'immutable_datetime',
            'delivered_channels' => 'array',
        ];
    }

    /**
     * jsonb columns come back from Postgres re-serialised (its own key order and number spelling), while the casts
     * write canonical JSON. Reading `$rx->snapshot` and then saving a permitted column — exactly what the PDF job
     * and the delivery action do — would therefore look like a snapshot rewrite to a string comparison and trip a
     * guard that is meant to catch real tampering. Compare the documents, not their spelling; the database trigger
     * does the same (`IS DISTINCT FROM` on jsonb is semantic), so the two layers agree.
     */
    private static function jsonUnchanged(self $rx, string $key): bool
    {
        if (! in_array($key, self::JSON_COLUMNS, true)) {
            return false;
        }

        $decode = static fn (mixed $value): mixed => is_string($value) ? json_decode($value, true) : $value;

        return $decode($rx->getAttributes()[$key] ?? null) == $decode($rx->getRawOriginal($key));
    }

    public function isDraft(): bool
    {
        return $this->status === PrescriptionStatus::Draft;
    }

    public function isIssued(): bool
    {
        return $this->status === PrescriptionStatus::Issued;
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Prescription, $this> */
    public function supersedes(): BelongsTo
    {
        return $this->belongsTo(self::class, 'supersedes_prescription_id');
    }

    /** @return HasOne<Prescription, $this> */
    public function supersededBy(): HasOne
    {
        return $this->hasOne(self::class, 'supersedes_prescription_id');
    }

    /** @return BelongsTo<Prescription, $this> */
    public function root(): BelongsTo
    {
        return $this->belongsTo(self::class, 'root_prescription_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function voidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by_user_id');
    }

    /** @return HasMany<PrescriptionItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<PrescriptionInvestigation, $this> */
    public function investigations(): HasMany
    {
        return $this->hasMany(PrescriptionInvestigation::class)->orderBy('sort_order');
    }

    /** @return HasMany<PrescriptionAdvice, $this> */
    public function advice(): HasMany
    {
        return $this->hasMany(PrescriptionAdvice::class)->orderBy('sort_order');
    }

    /** @return HasMany<PrescriptionReferral, $this> */
    public function referrals(): HasMany
    {
        return $this->hasMany(PrescriptionReferral::class)->orderBy('id');
    }

    /** @param  Builder<Prescription>  $query */
    public function scopeDraft(Builder $query): void
    {
        $query->where('status', PrescriptionStatus::Draft->value);
    }

    /** @param  Builder<Prescription>  $query */
    public function scopeIssued(Builder $query): void
    {
        $query->where('status', PrescriptionStatus::Issued->value);
    }

    /**
     * The version chain of a root, ordered by version (PRESCRIPTION.md §6.3).
     *
     * @return Collection<int, Prescription>
     */
    public static function versions(int $rootId): Collection
    {
        return self::query()->where(fn (Builder $q) => $q->where('root_prescription_id', $rootId)->orWhere('id', $rootId))->orderBy('version')->get();
    }

    /** The relation-free list of children, loaded in one go. */
    public function loadChildren(): self
    {
        return $this->load(['items', 'investigations', 'advice', 'referrals']);
    }
}
