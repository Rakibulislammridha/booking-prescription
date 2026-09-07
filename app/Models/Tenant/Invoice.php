<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\InvoiceStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\InvoiceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\DB;

/**
 * The patient-facing bill (SCHEMA §3.5). Money columns are integer paisa; `due_paisa` is a GENERATED column
 * (total − paid) and is deliberately absent from $fillable — Postgres rejects any write to it. A frozen invoice is corrected by new
 * rows (discounts / refunds / a fresh invoice), never by editing it.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $number
 * @property int $patient_id
 * @property int|null $appointment_id
 * @property int|null $visit_id
 * @property int|null $doctor_id
 * @property int $branch_id
 * @property InvoiceStatus $status
 * @property int $subtotal_paisa
 * @property int $discount_paisa
 * @property int $coupon_discount_paisa
 * @property int $vat_paisa
 * @property int $total_paisa
 * @property int $paid_paisa
 * @property int $due_paisa
 * @property CarbonImmutable|null $issued_at
 * @property CarbonImmutable|null $due_at
 * @property CarbonImmutable|null $paid_at
 * @property CarbonImmutable|null $voided_at
 * @property string|null $void_reason
 * @property int|null $created_by_user_id
 * @property int|null $cash_shift_id
 * @property string|null $notes
 * @property-read Patient $patient
 * @property-read Appointment|null $appointment
 * @property-read Doctor|null $doctor
 * @property-read Branch $branch
 * @property-read Collection<int, InvoiceItem> $items
 * @property-read Collection<int, Payment> $payments
 * @property-read Collection<int, Refund> $refunds
 * @property-read Collection<int, Discount> $discounts
 * @property-read CouponRedemption|null $couponRedemption
 */
final class Invoice extends TenantModel
{
    /** @use HasFactory<InvoiceFactory> */
    use HasFactory;

    protected static string $factory = InvoiceFactory::class;

    protected static bool $publicId = true;

    protected static bool $assertsTenantId = true;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = [
        'number', 'patient_id', 'appointment_id', 'doctor_id', 'branch_id', 'status', 'subtotal_paisa', 'discount_paisa',
        'coupon_discount_paisa', 'vat_paisa', 'total_paisa', 'paid_paisa', 'issued_at', 'paid_at', 'voided_at', 'void_reason',
    ];

    protected $table = 'invoices';

    protected $fillable = [
        'tenant_id', 'number', 'patient_id', 'appointment_id', 'visit_id', 'doctor_id', 'branch_id', 'status',
        'subtotal_paisa', 'discount_paisa', 'coupon_discount_paisa', 'vat_paisa', 'total_paisa', 'paid_paisa',
        'issued_at', 'due_at', 'paid_at', 'voided_at', 'void_reason', 'created_by_user_id', 'cash_shift_id', 'notes',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $invoice): void {
            if (blank($invoice->getAttribute('number'))) {
                $invoice->setAttribute('number', self::nextNumber());
            }
        });
    }

    /** `INV-2026-000045` from the per-schema sequence (SCHEMA §0.4). */
    public static function nextNumber(?int $year = null): string
    {
        $next = (int) DB::connection('pgsql')->scalar("select nextval('invoice_number_seq')");

        return sprintf('INV-%d-%06d', $year ?? (int) now()->format('Y'), $next);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => InvoiceStatus::class,
            'subtotal_paisa' => 'integer',
            'discount_paisa' => 'integer',
            'coupon_discount_paisa' => 'integer',
            'vat_paisa' => 'integer',
            'total_paisa' => 'integer',
            'paid_paisa' => 'integer',
            'due_paisa' => 'integer',
            'issued_at' => 'immutable_datetime',
            'due_at' => 'immutable_datetime',
            'paid_at' => 'immutable_datetime',
            'voided_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Appointment, $this> */
    public function appointment(): BelongsTo
    {
        return $this->belongsTo(Appointment::class);
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<InvoiceItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class)->orderBy('sort_order');
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<Refund, $this> */
    public function refunds(): HasMany
    {
        return $this->hasMany(Refund::class);
    }

    /** @return HasMany<Discount, $this> */
    public function discounts(): HasMany
    {
        return $this->hasMany(Discount::class);
    }

    /** @return HasOne<CouponRedemption, $this> */
    public function couponRedemption(): HasOne
    {
        return $this->hasOne(CouponRedemption::class);
    }

    /** @param  Builder<Invoice>  $query */
    public function scopeOutstanding(Builder $query): void
    {
        $query->where('due_paisa', '>', 0)->whereIn('status', [InvoiceStatus::Issued->value, InvoiceStatus::PartiallyPaid->value]);
    }

    /** @param  Builder<Invoice>  $query */
    public function scopeLive(Builder $query): void
    {
        $query->where('status', '!=', InvoiceStatus::Void->value);
    }

    public function isPayable(): bool
    {
        return $this->status->isPayable() && $this->due_paisa > 0;
    }

    public function isDraft(): bool
    {
        return $this->status === InvoiceStatus::Draft;
    }
}
