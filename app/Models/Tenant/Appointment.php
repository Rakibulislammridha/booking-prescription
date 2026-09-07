<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Serials\Enums\CancelReason;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\AppointmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The booking of every channel (SCHEMA §3.3): links patient, doctor, session and serial; snapshots the fee decision
 * (§5.10). Audited on create/update (a clinical write per BRIEF §8).
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $patient_id
 * @property int $doctor_id
 * @property int $branch_id
 * @property int|null $session_instance_id
 * @property int|null $serial_id
 * @property AppointmentType $type
 * @property BookingChannel $channel
 * @property AppointmentStatus $status
 * @property CarbonImmutable|null $scheduled_date
 * @property CarbonImmutable|null $slot_start_at
 * @property int $list_fee_paisa
 * @property int $fee_paisa
 * @property FeeRule $fee_rule
 * @property string|null $fee_rule_reason
 * @property PaymentStatus $payment_status
 * @property int|null $invoice_id
 * @property int|null $follow_up_of_visit_id
 * @property int|null $booked_by_user_id
 * @property bool $booked_by_patient
 * @property bool $is_telemedicine
 * @property string|null $notes
 * @property string|null $idempotency_key
 * @property string|null $client_event_id
 * @property int|null $reception_device_id
 * @property CancelReason|null $cancel_reason_code
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by_user_id
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $reminder_day_before_sent_at
 * @property CarbonImmutable|null $reminder_morning_sent_at
 * @property-read Patient $patient
 * @property-read Doctor $doctor
 * @property-read Branch $branch
 * @property-read SessionInstance|null $sessionInstance
 * @property-read Serial|null $serial
 * @property-read User|null $bookedBy
 */
final class Appointment extends TenantModel
{
    /** @use HasFactory<AppointmentFactory> */
    use HasFactory;

    protected static string $factory = AppointmentFactory::class;

    protected static bool $publicId = true;

    protected static bool $assertsTenantId = true;

    protected static bool $audited = true;

    /** @var array<int, string> */
    protected static array $auditedAttributes = [
        'patient_id', 'doctor_id', 'session_instance_id', 'serial_id', 'type', 'channel', 'status', 'scheduled_date', 'fee_paisa',
        'fee_rule', 'payment_status', 'cancel_reason_code', 'cancelled_at', 'follow_up_of_visit_id',
    ];

    protected $table = 'appointments';

    protected $fillable = [
        'tenant_id', 'patient_id', 'doctor_id', 'branch_id', 'session_instance_id', 'serial_id', 'type', 'channel', 'status',
        'scheduled_date', 'slot_start_at', 'list_fee_paisa', 'fee_paisa', 'fee_rule', 'fee_rule_reason', 'payment_status', 'invoice_id',
        'follow_up_of_visit_id', 'booked_by_user_id', 'booked_by_patient', 'is_telemedicine', 'notes', 'idempotency_key',
        'client_event_id', 'reception_device_id', 'cancel_reason_code', 'cancelled_at', 'cancelled_by_user_id', 'confirmed_at',
        'reminder_day_before_sent_at', 'reminder_morning_sent_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => AppointmentType::class,
            'channel' => BookingChannel::class,
            'status' => AppointmentStatus::class,
            'scheduled_date' => 'immutable_date',
            'slot_start_at' => 'immutable_datetime',
            'list_fee_paisa' => 'integer',
            'fee_paisa' => 'integer',
            'fee_rule' => FeeRule::class,
            'payment_status' => PaymentStatus::class,
            'booked_by_patient' => 'boolean',
            'is_telemedicine' => 'boolean',
            'cancel_reason_code' => CancelReason::class,
            'cancelled_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'reminder_day_before_sent_at' => 'immutable_datetime',
            'reminder_morning_sent_at' => 'immutable_datetime',
        ];
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

    /** @return BelongsTo<SessionInstance, $this> */
    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }

    /** @return BelongsTo<Serial, $this> */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(Serial::class);
    }

    /** @return BelongsTo<User, $this> */
    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by_user_id');
    }

    /**
     * Not cancelled / no-show (the partial unique "one live booking per patient per session" predicate).
     *
     * @param  Builder<Appointment>  $query
     */
    public function scopeLive(Builder $query): void
    {
        $query->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value]);
    }

    public function isPaid(): bool
    {
        return $this->payment_status === PaymentStatus::Paid;
    }
}
