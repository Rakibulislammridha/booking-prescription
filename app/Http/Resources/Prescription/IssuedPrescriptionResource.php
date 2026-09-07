<?php

declare(strict_types=1);

namespace App\Http\Resources\Prescription;

use App\Models\Tenant\Prescription;
use App\Models\Tenant\Visit;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An issued / amended / voided prescription as the panel shows it: the frozen snapshot (the ONLY render source,
 * I3/I6 — no catalog access, no child rows) + status/version chain + post-issue facts (pdf, prints, deliveries).
 *
 * @mixin Prescription
 */
final class IssuedPrescriptionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $snapshot = $this->snapshot?->toArray() ?? [];
        $latest = $this->supersededBy()->exists() === false;

        return self::brief($this->resource) + [
            'snapshot' => $snapshot,
            'snapshot_sha256' => $this->snapshot_sha256,
            'pad_snapshot' => $this->pad_snapshot,
            'is_latest' => $latest,
            'superseded_by' => $this->supersededBy !== null ? ['id' => $this->supersededBy->public_id, 'version' => $this->supersededBy->version, 'issued_at' => $this->supersededBy->issued_at?->toIso8601String()] : null,
            'versions' => Prescription::versions($this->root_prescription_id ?? $this->id)->map(fn (Prescription $v) => self::brief($v))->values()->all(),
            'pdf_status' => $this->pdf_path !== null ? 'ready' : 'pending',
            'printed_count' => $this->printed_count,
            'last_printed_at' => $this->last_printed_at?->toIso8601String(),
            'delivered_channels' => $this->delivered_channels,
            'voided' => $this->voided_at === null ? null : ['at' => $this->voided_at->toIso8601String(), 'reason' => $this->void_reason],
        ];
    }

    /** @return array<string, mixed> */
    public static function brief(Prescription $rx): array
    {
        return [
            'id' => $rx->public_id, 'version' => $rx->version, 'status' => $rx->status->value, 'language' => $rx->language->value,
            'verification_code' => $rx->verification_code, 'issued_at' => $rx->issued_at?->toIso8601String(), 'amend_reason' => $rx->amend_reason,
            'root_id' => $rx->root_prescription_id !== null && $rx->root_prescription_id !== $rx->id ? Prescription::query()->whereKey($rx->root_prescription_id)->value('public_id') : $rx->public_id,
            'supersedes_id' => $rx->supersedes_prescription_id !== null ? Prescription::query()->whereKey($rx->supersedes_prescription_id)->value('public_id') : null,
            'visit_id' => $rx->relationLoaded('visit') ? $rx->visit->public_id : Visit::query()->whereKey($rx->visit_id)->value('public_id'),
        ];
    }
}
