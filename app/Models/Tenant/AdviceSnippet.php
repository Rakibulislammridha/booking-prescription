<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Enums\AdviceCategory;
use Database\Factories\Tenant\AdviceSnippetFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Reusable advice / finding / complaint snippet (SCHEMA §3.4); doctor_id NULL = clinic library.
 *
 * @property int $id
 * @property int|null $doctor_id
 * @property string|null $shorthand
 * @property AdviceCategory|null $category
 * @property string $text
 * @property string|null $text_bn
 * @property bool $is_shared
 * @property int $use_count
 * @property bool $is_active
 */
final class AdviceSnippet extends TenantModel
{
    /** @use HasFactory<AdviceSnippetFactory> */
    use HasFactory;

    protected static string $factory = AdviceSnippetFactory::class;

    protected $table = 'advice_snippets';

    protected $fillable = ['doctor_id', 'shorthand', 'category', 'text', 'text_bn', 'is_shared', 'use_count', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['category' => AdviceCategory::class, 'is_shared' => 'boolean', 'use_count' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @param  Builder<AdviceSnippet>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<AdviceSnippet>  $query */
    public function scopeVisibleTo(Builder $query, int $doctorId): void
    {
        $query->where(fn (Builder $q) => $q->where('doctor_id', $doctorId)->orWhereNull('doctor_id')->orWhere('is_shared', true));
    }
}
