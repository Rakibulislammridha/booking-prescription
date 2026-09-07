<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use Database\Factories\Central\PlanFeatureFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $plan_id
 * @property PlanFeatureKey $feature_key
 * @property int|null $limit_value
 * @property bool $enabled
 */
final class PlanFeature extends CentralModel
{
    /** @use HasFactory<PlanFeatureFactory> */
    use HasFactory;

    protected static string $factory = PlanFeatureFactory::class;

    protected $table = 'public.plan_features';

    protected $fillable = ['plan_id', 'feature_key', 'limit_value', 'enabled'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['feature_key' => PlanFeatureKey::class, 'limit_value' => 'integer', 'enabled' => 'boolean'];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
