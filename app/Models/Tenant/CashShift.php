<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Billing\Enums\CashShiftStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\CashShiftFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The cash drawer of one receptionist at one branch (SCHEMA §3.5, BRIEF §5.F). A partial unique index allows a
 * single open shift per user, so two tabs cannot open two drawers.
 *
 * @property int $id
 * @property int $user_id
 * @property int $branch_id
 * @property CashShiftStatus $status
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $closed_at
 * @property int $opening_float_paisa
 * @property int|null $expected_cash_paisa
 * @property int|null $counted_cash_paisa
 * @property int|null $variance_paisa
 * @property int|null $card_total_paisa
 * @property int|null $mobile_money_total_paisa
 * @property string|null $closing_note
 * @property int|null $closed_by_user_id
 * @property-read User $user
 * @property-read Branch $branch
 * @property-read Collection<int, Payment> $payments
 */
final class CashShift extends TenantModel
{
    /** @use HasFactory<CashShiftFactory> */
    use HasFactory;

    protected static string $factory = CashShiftFactory::class;

    protected static bool $audited = true;

    protected $table = 'cash_shifts';

    protected $fillable = [
        'user_id', 'branch_id', 'status', 'opened_at', 'closed_at', 'opening_float_paisa', 'expected_cash_paisa',
        'counted_cash_paisa', 'variance_paisa', 'card_total_paisa', 'mobile_money_total_paisa', 'closing_note',
        'closed_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => CashShiftStatus::class,
            'opened_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'opening_float_paisa' => 'integer',
            'expected_cash_paisa' => 'integer',
            'counted_cash_paisa' => 'integer',
            'variance_paisa' => 'integer',
            'card_total_paisa' => 'integer',
            'mobile_money_total_paisa' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
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

    /** @param  Builder<CashShift>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', CashShiftStatus::Open->value);
    }

    public function isOpen(): bool
    {
        return $this->status === CashShiftStatus::Open;
    }
}
