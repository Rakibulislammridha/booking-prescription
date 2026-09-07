<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\PatientConsent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * signature_data (ENC) stays server-side.
 *
 * @mixin PatientConsent
 */
final class ConsentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'status' => $this->status->value,
            'policy_version' => $this->policy_version,
            'channel' => $this->channel->value,
            'captured_by_user_id' => $this->captured_by_user_id,
            'has_signature' => $this->signature_data !== null,
            'evidence' => $this->evidence,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
        ];
    }
}
