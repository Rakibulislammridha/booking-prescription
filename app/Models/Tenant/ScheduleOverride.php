<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Scheduling\Enums\OverrideType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\ScheduleOverrideFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-date deviation from the weekly template (SCHEMA §3.3). session_code NULL = every session that day.
 *
 * @property int $id
 * @property int $doctor_id
 * @property int $branch_id
 * @property CarbonImmutable $override_date
 * @property string|null $session_code
 * @property OverrideType $type
 * @property int|null $delay_minutes
 * @property string|null $new_start_time
 * @property string|null $new_end_time
 * @property int|null $new_max_serials
 * @property int|null $new_online_quota
 * @property int|null $new_counter_quota
 * @property int|null $new_buffer_quota
 * @property string|null $reason
 * @property bool $notify_patients
 * @property CarbonImmutable|null $applied_at
 * @property int|null $created_by_user_id
 * @property-read Doctor $doctor
 * @property-read Branch $branch
 */
final class ScheduleOverride extends TenantModel
{
    /** @use HasFactory<ScheduleOverrideFactory> */
    use HasFactory;

    protected static string $factory = ScheduleOverrideFactory::class;

    protected $table = 'schedule_overrides';

    protected $fillable = [
        'doctor_id', 'branch_id', 'override_date', 'session_code', 'type', 'delay_minutes', 'new_start_time', 'new_end_time',
        'new_max_serials', 'new_online_quota', 'new_counter_quota', 'new_buffer_quota', 'reason', 'notify_patients', 'applied_at', 'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'override_date' => 'immutable_date',
            'type' => OverrideType::class,
            'delay_minutes' => 'integer',
            'new_max_serials' => 'integer',
            'new_online_quota' => 'integer',
            'new_counter_quota' => 'integer',
            'new_buffer_quota' => 'integer',
            'notify_patients' => 'boolean',
            'applied_at' => 'immutable_datetime',
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

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function appliesTo(string $sessionCode): bool
    {
        return $this->session_code === null || $this->session_code === $sessionCode;
    }
}
