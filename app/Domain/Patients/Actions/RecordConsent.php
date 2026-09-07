<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\ConsentData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientConsent;
use Carbon\CarbonImmutable;

/** Append-only: granting or revoking is always a new row (SCHEMA §3.2). */
final class RecordConsent
{
    public function handle(Patient $patient, ConsentData $data, Actor $actor): PatientConsent
    {
        return PatientConsent::query()->create([
            'patient_id' => $patient->id,
            'type' => $data->type,
            'status' => $data->status,
            'policy_version' => $data->policyVersion,
            'channel' => $data->channel,
            'captured_by_user_id' => $actor->userId,
            'ip' => $data->ip ?? $actor->ip,
            'user_agent' => $data->userAgent,
            'signature_data' => $data->signatureData,
            'evidence' => $data->evidence,
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
