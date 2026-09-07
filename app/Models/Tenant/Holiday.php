<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\HolidayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $branch_id
 * @property CarbonImmutable $holiday_date
 * @property string $name
 * @property string|null $name_bn
 * @property int|null $created_by_user_id
 */
final class Holiday extends TenantModel
{
    /** @use HasFactory<HolidayFactory> */
    use HasFactory;

    protected static string $factory = HolidayFactory::class;

    protected $table = 'holidays';

    protected $fillable = ['branch_id', 'holiday_date', 'name', 'name_bn', 'created_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['holiday_date' => 'immutable_date'];
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
