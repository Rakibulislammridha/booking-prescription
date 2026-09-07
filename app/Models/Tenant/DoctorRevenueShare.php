<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\RevenueShareItemType;
use App\Domain\Billing\Enums\RevenueShareType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\DoctorRevenueShareFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A commission RULE (SCHEMA §3.5). The rule that applied is snapshotted onto the invoice item, so editing or
 * retiring a rule never changes a historical split.
 *
 * @property int $id
 * @property int $doctor_id
 * @property int|null $branch_id
 * @property RevenueShareItemType $item_type
 * @property RevenueShareType $share_type
 * @property string $share_value
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property bool $is_active
 * @property int|null $created_by_user_id
 * @property-read Doctor $doctor
 * @property-read Branch|null $branch
 */
final class DoctorRevenueShare extends TenantModel
{
    /** @use HasFactory<DoctorRevenueShareFactory> */
    use HasFactory;

    protected static string $factory = DoctorRevenueShareFactory::class;

    protected static bool $audited = true;

    protected $table = 'doctor_revenue_shares';

    protected $fillable = [
        'doctor_id', 'branch_id', 'item_type', 'share_type', 'share_value', 'effective_from', 'effective_to',
        'is_active', 'created_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'item_type' => RevenueShareItemType::class,
            'share_type' => RevenueShareType::class,
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

    /** @param  Builder<DoctorRevenueShare>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
