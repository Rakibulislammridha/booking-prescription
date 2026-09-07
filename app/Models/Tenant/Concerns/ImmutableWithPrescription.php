<?php

declare(strict_types=1);

namespace App\Models\Tenant\Concerns;

use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Exceptions\ImmutablePrescriptionException;
use App\Models\Tenant\Prescription;
use Illuminate\Database\Eloquent\Model;

/**
 * Child-row guard (PRESCRIPTION.md §6.5): saving/deleting throws unless the parent prescription is a draft. One
 * cached parent read per model instance; the DB trigger prescription_children_immutable_trg is the backstop.
 */
trait ImmutableWithPrescription
{
    public static function bootImmutableWithPrescription(): void
    {
        foreach (['saving', 'deleting'] as $event) {
            static::registerModelEvent($event, function (Model $child): void {
                $prescriptionId = (int) $child->getAttribute('prescription_id');

                if ($prescriptionId === 0) {
                    return;                                           // validation/FK will reject the row anyway
                }

                $status = self::parentStatus($child, $prescriptionId);

                if ($status !== PrescriptionStatus::Draft->value) {
                    throw new ImmutablePrescriptionException($prescriptionId, $status ?? 'unknown', class_basename($child).' rows are immutable.');
                }
            });
        }
    }

    private static function parentStatus(Model $child, int $prescriptionId): ?string
    {
        if ($child->relationLoaded('prescription') && $child->getRelation('prescription') instanceof Prescription && $child->getRelation('prescription')->id === $prescriptionId) {
            return $child->getRelation('prescription')->status->value;
        }

        $status = Prescription::query()->whereKey($prescriptionId)->value('status');

        return $status === null ? null : (string) ($status instanceof PrescriptionStatus ? $status->value : $status);
    }
}
