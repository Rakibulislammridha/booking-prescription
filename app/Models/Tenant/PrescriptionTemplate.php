<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PrescriptionTemplateFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Whole-prescription template, per doctor or clinic-shared (SCHEMA §3.4). A template with an icd10_code is a
 * "protocol" (PRESCRIPTION.md §3.7).
 *
 * @property int $id
 * @property int|null $doctor_id
 * @property string $name
 * @property string|null $shorthand
 * @property string|null $icd10_code
 * @property string|null $diagnosis_title
 * @property bool $is_shared
 * @property array<string, mixed> $body
 * @property int $use_count
 * @property CarbonImmutable|null $last_used_at
 * @property int|null $created_by_user_id
 * @property CarbonImmutable|null $deleted_at
 * @property-read Collection<int, PrescriptionTemplateItem> $items
 */
final class PrescriptionTemplate extends TenantModel
{
    /** @use HasFactory<PrescriptionTemplateFactory> */
    use HasFactory, SoftDeletes;

    protected static string $factory = PrescriptionTemplateFactory::class;

    protected $table = 'prescription_templates';

    protected $fillable = ['doctor_id', 'name', 'shorthand', 'icd10_code', 'diagnosis_title', 'is_shared', 'body', 'use_count', 'last_used_at', 'created_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_shared' => 'boolean', 'body' => 'array', 'use_count' => 'integer', 'last_used_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return HasMany<PrescriptionTemplateItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(PrescriptionTemplateItem::class)->orderBy('sort_order');
    }

    /**
     * Templates a doctor may see: own + clinic-shared (doctor_id null) + other doctors' `is_shared` ones.
     *
     * @param  Builder<PrescriptionTemplate>  $query
     */
    public function scopeVisibleTo(Builder $query, int $doctorId): void
    {
        $query->where(fn (Builder $q) => $q->where('doctor_id', $doctorId)->orWhereNull('doctor_id')->orWhere('is_shared', true));
    }
}
