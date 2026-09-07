<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Exceptions\NotLatestVersion;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Prescription;
use Illuminate\Support\Facades\DB;

/** PRESCRIPTION.md §6.4: status = voided + voided_*; snapshot untouched; printing adds a VOID watermark. */
final class VoidPrescription
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Prescription $issued, string $reason, Actor $actor): Prescription
    {
        return DB::transaction(function () use ($issued, $reason, $actor): Prescription {
            /** @var Prescription $rx */
            $rx = Prescription::query()->whereKey($issued->id)->lockForUpdate()->firstOrFail();

            if ($rx->status !== PrescriptionStatus::Issued) {
                throw new NotLatestVersion($rx->id, "status is {$rx->status->value}; only an issued prescription can be voided");
            }

            $rx->forceFill(['status' => PrescriptionStatus::Voided, 'voided_at' => now(), 'voided_by_user_id' => $actor->userId, 'void_reason' => $reason])->save();
            $this->auditor->voided($rx, $reason);

            return $rx;
        });
    }
}
