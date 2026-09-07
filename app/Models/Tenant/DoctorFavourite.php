<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\DoctorFavouriteFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Ranked quick-pick row per doctor per diagnosis (SCHEMA §3.4); pinned rows are never rewritten by learning.
 *
 * @property int $id
 * @property int $doctor_id
 * @property string|null $icd10_code
 * @property int $generic_id
 * @property int|null $brand_id
 * @property int|null $custom_brand_id
 * @property int|null $strength_id
 * @property string $label
 * @property array<string, mixed> $default_dose
 * @property bool $is_pinned
 * @property int $use_count
 * @property int $rank
 * @property CarbonImmutable|null $last_used_at
 */
final class DoctorFavourite extends TenantModel
{
    /** @use HasFactory<DoctorFavouriteFactory> */
    use HasFactory;

    protected static string $factory = DoctorFavouriteFactory::class;

    protected $table = 'doctor_favourites';

    protected $fillable = ['doctor_id', 'icd10_code', 'generic_id', 'brand_id', 'custom_brand_id', 'strength_id', 'label', 'default_dose', 'is_pinned', 'use_count', 'rank', 'last_used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generic_id' => 'integer', 'brand_id' => 'integer', 'custom_brand_id' => 'integer', 'strength_id' => 'integer',
            'default_dose' => 'array', 'is_pinned' => 'boolean', 'use_count' => 'integer', 'rank' => 'integer', 'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** Search-document id of the preferred presentation (s{strength}, c{custom}, g{generic}) — the boost key. */
    public function presentationKey(): string
    {
        if ($this->custom_brand_id !== null) {
            return 'c'.$this->custom_brand_id;
        }

        if ($this->strength_id !== null) {
            return 's'.$this->strength_id;
        }

        return 'g'.$this->generic_id;
    }
}
