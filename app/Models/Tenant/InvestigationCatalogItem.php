<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Enums\InvestigationCategory;
use Database\Factories\Tenant\InvestigationCatalogItemFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The clinic's own priced test/imaging list (SCHEMA §3.4 `investigation_catalog`; no lab workflow).
 *
 * @property int $id
 * @property int|null $branch_id
 * @property string|null $code
 * @property string $name
 * @property string|null $name_bn
 * @property InvestigationCategory $category
 * @property int $price_paisa
 * @property string|null $prep_instructions
 * @property string|null $prep_instructions_bn
 * @property bool $is_active
 * @property int $sort_order
 */
final class InvestigationCatalogItem extends TenantModel
{
    /** @use HasFactory<InvestigationCatalogItemFactory> */
    use HasFactory;

    protected static string $factory = InvestigationCatalogItemFactory::class;

    protected $table = 'investigation_catalog';

    protected $fillable = ['branch_id', 'code', 'name', 'name_bn', 'category', 'price_paisa', 'prep_instructions', 'prep_instructions_bn', 'is_active', 'sort_order'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['category' => InvestigationCategory::class, 'price_paisa' => 'integer', 'is_active' => 'boolean', 'sort_order' => 'integer'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @param  Builder<InvestigationCatalogItem>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<InvestigationCatalogItem>  $query */
    public function scopeForBranch(Builder $query, ?int $branchId): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('branch_id')->when($branchId !== null, fn (Builder $w) => $w->orWhere('branch_id', $branchId)));
    }
}
