<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Clinic\Enums\LeaveType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\DoctorLeaveFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $doctor_id
 * @property int|null $branch_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property LeaveType $type
 * @property string|null $reason
 * @property bool $notify_patients
 * @property CarbonImmutable|null $notified_at
 * @property bool $is_cancelled
 * @property int|null $created_by_user_id
 * @property-read User|null $createdBy
 */
final class DoctorLeave extends TenantModel
{
    /** @use HasFactory<DoctorLeaveFactory> */
    use HasFactory;

    protected static string $factory = DoctorLeaveFactory::class;

    protected $table = 'doctor_leaves';

    protected $fillable = ['doctor_id', 'branch_id', 'starts_on', 'ends_on', 'type', 'reason', 'notify_patients', 'notified_at', 'is_cancelled', 'created_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'type' => LeaveType::class,
            'notify_patients' => 'boolean',
            'notified_at' => 'immutable_datetime',
            'is_cancelled' => 'boolean',
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
}
