<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Scheduling\Enums\ScheduleMode;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\DoctorScheduleFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Weekly recurring template: one row per (doctor, branch, weekday, session_code) (SCHEMA §3.3).
 *
 * @property int $id
 * @property int $doctor_id
 * @property int $branch_id
 * @property int $weekday
 * @property string $session_code
 * @property string|null $session_label
 * @property string $start_time
 * @property string $end_time
 * @property ScheduleMode $mode
 * @property int|null $slot_minutes
 * @property int $max_serials
 * @property int $online_quota
 * @property int $counter_quota
 * @property int $buffer_quota
 * @property int $avg_consult_minutes
 * @property int|null $fee_new_paisa
 * @property int|null $fee_followup_paisa
 * @property int|null $auto_noshow_after
 * @property bool $works_on_holidays
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property bool $is_active
 * @property-read Doctor $doctor
 * @property-read Branch $branch
 */
final class DoctorSchedule extends TenantModel
{
    /** @use HasFactory<DoctorScheduleFactory> */
    use HasFactory;

    protected static string $factory = DoctorScheduleFactory::class;

    protected $table = 'doctor_schedules';

    protected $fillable = [
        'doctor_id', 'branch_id', 'weekday', 'session_code', 'session_label', 'start_time', 'end_time', 'mode', 'slot_minutes',
        'max_serials', 'online_quota', 'counter_quota', 'buffer_quota', 'avg_consult_minutes', 'fee_new_paisa', 'fee_followup_paisa',
        'auto_noshow_after', 'works_on_holidays', 'effective_from', 'effective_to', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'mode' => ScheduleMode::class,
            'slot_minutes' => 'integer',
            'max_serials' => 'integer',
            'online_quota' => 'integer',
            'counter_quota' => 'integer',
            'buffer_quota' => 'integer',
            'avg_consult_minutes' => 'integer',
            'fee_new_paisa' => 'integer',
            'fee_followup_paisa' => 'integer',
            'auto_noshow_after' => 'integer',
            'works_on_holidays' => 'boolean',
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
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

    /** @return HasMany<SessionInstance, $this> */
    public function sessionInstances(): HasMany
    {
        return $this->hasMany(SessionInstance::class);
    }

    /** @param  Builder<DoctorSchedule>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Templates in force on a calendar date (effective window + weekday).
     *
     * @param  Builder<DoctorSchedule>  $query
     */
    public function scopeEffectiveOn(Builder $query, CarbonImmutable $date): void
    {
        $query->where('weekday', $date->dayOfWeek)
            ->whereDate('effective_from', '<=', $date->toDateString())
            ->where(fn (Builder $q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date->toDateString()));
    }
}
