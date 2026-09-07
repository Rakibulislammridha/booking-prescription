<?php

declare(strict_types=1);

namespace App\Models\Central;

use Carbon\CarbonImmutable;
use Database\Factories\Central\PlanFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property int $price_monthly_paisa
 * @property int $price_yearly_paisa
 * @property int $trial_days
 * @property bool $is_public
 * @property bool $is_addon
 * @property int $sort_order
 * @property CarbonImmutable|null $archived_at
 */
final class Plan extends CentralModel
{
    /** @use HasFactory<PlanFactory> */
    use HasFactory;

    protected static string $factory = PlanFactory::class;

    protected $table = 'public.plans';

    protected $fillable = [
        'code', 'name', 'description', 'price_monthly_paisa', 'price_yearly_paisa', 'trial_days', 'is_public', 'is_addon', 'sort_order', 'archived_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'price_monthly_paisa' => 'integer',
            'price_yearly_paisa' => 'integer',
            'trial_days' => 'integer',
            'is_public' => 'boolean',
            'is_addon' => 'boolean',
            'sort_order' => 'integer',
            'archived_at' => 'immutable_datetime',
        ];
    }

    /** @return HasMany<PlanFeature, $this> */
    public function features(): HasMany
    {
        return $this->hasMany(PlanFeature::class);
    }

    /** @return HasMany<Subscription, $this> */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
