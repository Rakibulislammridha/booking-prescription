<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Domain\Scheduling\Enums\SessionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\SessionInstanceFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The materialised (branch, doctor, date, session_code) — the LOCKED serial scope (SCHEMA §3.3, SERIAL_ENGINE §2).
 *
 * @property int $id
 * @property string $public_id
 * @property int $branch_id
 * @property int $doctor_id
 * @property CarbonImmutable $session_date
 * @property string $session_code
 * @property int|null $doctor_schedule_id
 * @property ScheduleMode $mode
 * @property int|null $slot_minutes
 * @property SessionStatus $status
 * @property CarbonImmutable $planned_start_at
 * @property CarbonImmutable $planned_end_at
 * @property CarbonImmutable|null $actual_start_at
 * @property CarbonImmutable|null $actual_end_at
 * @property int $pause_seconds
 * @property int $delay_minutes
 * @property int $max_serials
 * @property int $online_quota
 * @property int $counter_quota
 * @property int $buffer_quota
 * @property int $avg_consult_seconds
 * @property int $consult_samples
 * @property int|null $now_serving_serial_id
 * @property CarbonImmutable|null $last_called_at
 * @property int $booked_count
 * @property int $checked_in_count
 * @property int $in_consultation_count
 * @property int $completed_count
 * @property int $no_show_count
 * @property int $cancelled_count
 * @property int $postponed_count
 * @property int $auto_noshow_after
 * @property int $fee_new_paisa
 * @property int $fee_followup_paisa
 * @property int $version
 * @property string|null $cancel_reason
 * @property int|null $closed_by_user_id
 * @property string|null $notes
 * @property-read Branch $branch
 * @property-read Doctor $doctor
 * @property-read DoctorSchedule|null $schedule
 * @property-read Serial|null $nowServing
 * @property-read Collection<int, SerialPool> $pools
 * @property-read Collection<int, Serial> $serials
 * @property-read Collection<int, SerialBlock> $blocks
 * @property-read Collection<int, SerialEvent> $events
 */
final class SessionInstance extends TenantModel
{
    /** @use HasFactory<SessionInstanceFactory> */
    use HasFactory;

    protected static string $factory = SessionInstanceFactory::class;

    protected static bool $publicId = true;

    protected $table = 'session_instances';

    protected $fillable = [
        'public_id', 'branch_id', 'doctor_id', 'session_date', 'session_code', 'doctor_schedule_id', 'mode', 'slot_minutes', 'status',
        'planned_start_at', 'planned_end_at', 'actual_start_at', 'actual_end_at', 'pause_seconds', 'delay_minutes',
        'max_serials', 'online_quota', 'counter_quota', 'buffer_quota', 'avg_consult_seconds', 'consult_samples',
        'now_serving_serial_id', 'last_called_at', 'auto_noshow_after', 'fee_new_paisa', 'fee_followup_paisa', 'version',
        'cancel_reason', 'closed_by_user_id', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'session_date' => 'immutable_date',
            'mode' => ScheduleMode::class,
            'slot_minutes' => 'integer',
            'status' => SessionStatus::class,
            'planned_start_at' => 'immutable_datetime',
            'planned_end_at' => 'immutable_datetime',
            'actual_start_at' => 'immutable_datetime',
            'actual_end_at' => 'immutable_datetime',
            'pause_seconds' => 'integer',
            'delay_minutes' => 'integer',
            'max_serials' => 'integer',
            'online_quota' => 'integer',
            'counter_quota' => 'integer',
            'buffer_quota' => 'integer',
            'avg_consult_seconds' => 'integer',
            'consult_samples' => 'integer',
            'last_called_at' => 'immutable_datetime',
            'booked_count' => 'integer',
            'checked_in_count' => 'integer',
            'in_consultation_count' => 'integer',
            'completed_count' => 'integer',
            'no_show_count' => 'integer',
            'cancelled_count' => 'integer',
            'postponed_count' => 'integer',
            'auto_noshow_after' => 'integer',
            'fee_new_paisa' => 'integer',
            'fee_followup_paisa' => 'integer',
            'version' => 'integer',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<DoctorSchedule, $this> */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(DoctorSchedule::class, 'doctor_schedule_id');
    }

    /** @return BelongsTo<Serial, $this> */
    public function nowServing(): BelongsTo
    {
        return $this->belongsTo(Serial::class, 'now_serving_serial_id');
    }

    /** @return HasMany<SerialPool, $this> */
    public function pools(): HasMany
    {
        return $this->hasMany(SerialPool::class);
    }

    /** @return HasMany<Serial, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(Serial::class);
    }

    /** @return HasMany<SerialBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(SerialBlock::class);
    }

    /** @return HasMany<SerialEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SerialEvent::class);
    }

    /** @param  Builder<SessionInstance>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', SessionStatus::open());
    }

    /** @param  Builder<SessionInstance>  $query */
    public function scopeForDay(Builder $query, int $branchId, int $doctorId, CarbonImmutable $date): void
    {
        $query->where('branch_id', $branchId)->where('doctor_id', $doctorId)->whereDate('session_date', $date->toDateString());
    }

    public function acceptsSerials(): bool
    {
        return $this->status->acceptsSerials();
    }

    /** planned_start_at + delay_minutes — the time the ETA and the auto no-show grace start from. */
    public function expectedStartAt(): CarbonImmutable
    {
        return $this->planned_start_at->addMinutes($this->delay_minutes);
    }
}
