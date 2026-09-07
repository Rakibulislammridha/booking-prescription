<?php

declare(strict_types=1);

namespace App\Models\Central;

use App\Domain\SaaS\Enums\UsageMetric;
use Database\Factories\Central\UsageCounterFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $tenant_id
 * @property UsageMetric $metric
 * @property string $period
 * @property int $value
 * @property int|null $limit_snapshot
 */
final class UsageCounter extends CentralModel
{
    /** @use HasFactory<UsageCounterFactory> */
    use HasFactory;

    protected static string $factory = UsageCounterFactory::class;

    protected $table = 'public.usage_counters';

    protected $fillable = ['tenant_id', 'metric', 'period', 'value', 'limit_snapshot'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['metric' => UsageMetric::class, 'value' => 'integer', 'limit_snapshot' => 'integer'];
    }

    /** @return BelongsTo<Tenant, $this> */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
