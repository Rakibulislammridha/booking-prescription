<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data\Casts;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Services\CanonicalJson;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * prescriptions.snapshot ↔ PrescriptionSnapshot (read-only DTO). Written exactly once at issue: the setter accepts the
 * array built by SnapshotBuilder (or the DTO) and stores canonical JSON so the stored text equals what snapshot_sha256
 * was computed over.
 *
 * @implements CastsAttributes<PrescriptionSnapshot|null, array<string, mixed>|PrescriptionSnapshot|null>
 */
final class AsPrescriptionSnapshot implements CastsAttributes
{
    /** @param  array<string, mixed>  $attributes */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?PrescriptionSnapshot
    {
        if ($value === null || $value === '') {
            return null;
        }

        $decoded = is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $value;

        return new PrescriptionSnapshot(is_array($decoded) ? $decoded : []);
    }

    /** @param  array<string, mixed>  $attributes */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof PrescriptionSnapshot) {
            return CanonicalJson::encode($value->toArray());
        }

        return CanonicalJson::encode($value);
    }
}
