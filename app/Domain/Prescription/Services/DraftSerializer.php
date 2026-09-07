<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Safety\SafetyReport;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionAdvice;
use App\Models\Tenant\PrescriptionInvestigation;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\PrescriptionReferral;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;

/**
 * The wire shapes the writer consumes (PRESCRIPTION.md §1.2 PrescriptionDraft, §4.13 response, §4.2 VitalsRow):
 * one place so page props, the draft-save response and the issued view describe one shape per model.
 */
final class DraftSerializer
{
    public function __construct(private readonly DisplayFormatter $display) {}

    /**
     * PrescriptionDraft: the draft incl. items (parsed + snapshot + display), investigations, advice, referrals.
     *
     * @param  array<string, array<int, string>>  $keys  client keys by row id per list (items / investigations / advice / referrals), echoed back after a save
     * @return array<string, mixed>
     */
    public function draft(Prescription $rx, ?SafetyReport $report = null, array $keys = []): array
    {
        $rx->loadMissing(['items', 'investigations', 'advice', 'referrals']);
        $k = fn (string $list, int $id, string $fallback) => $keys[$list][$id] ?? $fallback;

        return [
            'id' => $rx->public_id,
            'version' => $rx->version,
            'status' => $rx->status->value,
            'language' => $rx->language->value,
            'root_id' => $rx->root_prescription_id !== null && $rx->root_prescription_id !== $rx->id ? Prescription::query()->whereKey($rx->root_prescription_id)->value('public_id') : $rx->public_id,
            'supersedes_id' => $rx->supersedes_prescription_id !== null ? Prescription::query()->whereKey($rx->supersedes_prescription_id)->value('public_id') : null,
            'amend_reason' => $rx->amend_reason,
            'updated_at' => $rx->updated_at?->toIso8601String(),
            'mode' => $rx->handwriting_image_path !== null ? 'handwriting' : 'structured',
            'handwriting_image_path' => $rx->handwriting_image_path,
            'drawing_json' => $rx->drawing_json,
            'drawing_image_path' => $rx->drawing_image_path,
            'items' => $rx->items->map(fn (PrescriptionItem $i) => $this->item($i, $k('items', $i->id, $i->clientKey())))->values()->all(),
            'investigations' => $rx->investigations->map(fn (PrescriptionInvestigation $x) => $this->investigation($x) + ['key' => $k('investigations', $x->id, $x->clientKey())])->values()->all(),
            'advice' => $rx->advice->map(fn (PrescriptionAdvice $a) => $this->advice($a) + ['key' => $k('advice', $a->id, $a->clientKey())])->values()->all(),
            'referrals' => $rx->referrals->map(fn (PrescriptionReferral $r) => $this->referral($r) + ['key' => $k('referrals', $r->id, $r->clientKey())])->values()->all(),
            'alerts' => $report?->alertsArray() ?? [],
            'issue_blocked_by' => $report !== null ? $report->issueBlockedBy : [],
        ];
    }

    /** @return array<string, mixed> */
    public function item(PrescriptionItem $i, ?string $key = null): array
    {
        $parsed = $i->dose_json !== [] ? ParsedLine::fromArray($i->dose_json) : null;

        return [
            'key' => $key ?? $i->clientKey(),
            'id' => $i->id,
            'sort_order' => $i->sort_order,
            'drug' => $i->generic_id === null && $i->custom_brand_id === null ? null : [
                'kind' => $i->custom_brand_id !== null ? 'custom' : ($i->strength_id !== null ? 'presentation' : 'generic'),
                'generic_id' => $i->generic_id, 'brand_id' => $i->brand_id, 'custom_brand_id' => $i->custom_brand_id, 'strength_id' => $i->strength_id,
                'generic_name' => $i->generic_name, 'brand_name' => $i->brand_name, 'strength' => $i->strength, 'form' => $i->form, 'route' => $i->route,
                'info_slug' => $i->info_url_slug,
            ],
            'shorthand' => (string) ($i->dose_json['raw'] ?? ''),
            'parsed' => $i->dose_json === [] ? null : $i->dose_json,
            'snapshot' => ['generic_name' => $i->generic_name, 'brand_name' => $i->brand_name, 'strength' => $i->strength, 'form' => $i->form, 'route' => $i->route],
            'display' => $parsed !== null ? $this->display->item($parsed) : null,
            'quantity' => $i->quantity, 'quantity_unit' => $i->quantity_unit, 'duration_days' => $i->duration_days, 'duration_text' => $i->duration_text,
            'timing' => $i->timing->value, 'instruction' => $i->instruction, 'instruction_bn' => $i->instruction_bn, 'is_continued' => $i->is_continued,
            'safety_overrides' => $i->safety_overrides,
        ];
    }

    /** @return array<string, mixed> */
    public function investigation(PrescriptionInvestigation $x): array
    {
        return [
            'key' => $x->clientKey(), 'id' => $x->id, 'sort_order' => $x->sort_order, 'investigation_catalog_id' => $x->investigation_catalog_id,
            'name' => $x->name, 'name_bn' => $x->name_bn, 'price_paisa' => $x->price_paisa, 'external_diagnostic_centre_id' => $x->external_diagnostic_centre_id,
            'referral_note' => $x->referral_note, 'is_urgent' => $x->is_urgent,
        ];
    }

    /** @return array<string, mixed> */
    public function advice(PrescriptionAdvice $a): array
    {
        return ['key' => $a->clientKey(), 'id' => $a->id, 'sort_order' => $a->sort_order, 'advice_snippet_id' => $a->advice_snippet_id, 'text' => $a->text, 'text_bn' => $a->text_bn];
    }

    /** @return array<string, mixed> */
    public function referral(PrescriptionReferral $r): array
    {
        return [
            'key' => $r->clientKey(), 'id' => $r->id, 'type' => $r->type->value, 'referred_to_doctor_id' => $r->referred_to_doctor_id,
            'external_diagnostic_centre_id' => $r->external_diagnostic_centre_id, 'referred_to_name' => $r->referred_to_name,
            'referred_to_specialty' => $r->referred_to_specialty, 'note' => $r->note, 'is_urgent' => $r->is_urgent,
        ];
    }

    /**
     * VisitBrief / the writer's `visit` prop (PRESCRIPTION.md §1.2).
     *
     * @return array<string, mixed>
     */
    public function visit(Visit $visit): array
    {
        $serial = $visit->relationLoaded('serial') ? $visit->serial : null;
        $session = $visit->relationLoaded('sessionInstance') ? $visit->sessionInstance : null;

        return [
            'id' => $visit->public_id,
            'status' => $visit->status->value,
            'type' => $visit->type->value,
            'serial_display' => $serial?->display_code,
            'session_code' => $session?->session_code,
            'started_at' => $visit->started_at->toIso8601String(),
            'ended_at' => $visit->ended_at?->toIso8601String(),
            'appointment_id' => $visit->appointment_id,
            'chief_complaints' => array_values($visit->chief_complaints),
            'examination_findings' => $visit->examination_findings,
            'diagnoses' => array_values($visit->diagnoses),
            'follow_up_on' => $visit->follow_up_on?->toDateString(),
            'follow_up_note' => $visit->follow_up_note,
            'current_prescription_id' => $visit->current_prescription_id !== null ? Prescription::query()->whereKey($visit->current_prescription_id)->value('public_id') : null,
        ];
    }

    /**
     * VitalsRow (PRESCRIPTION.md §4.2).
     *
     * @return array<string, mixed>
     */
    public function vitals(Vital $v): array
    {
        $by = $v->relationLoaded('recordedBy') ? $v->recordedBy : $v->recordedBy()->first();

        return [
            'id' => $v->id, 'visit_id' => $v->visit_id, 'bp_systolic' => $v->bp_systolic, 'bp_diastolic' => $v->bp_diastolic, 'pulse_bpm' => $v->pulse_bpm,
            'temperature_c' => $v->temperature_c, 'spo2_percent' => $v->spo2_percent, 'respiratory_rate' => $v->respiratory_rate, 'weight_kg' => $v->weight_kg,
            'height_cm' => $v->height_cm, 'bmi' => $v->bmi, 'blood_glucose_mgdl' => $v->blood_glucose_mgdl, 'notes' => $v->notes,
            'recorded_by' => $by === null ? null : ['id' => $by->id, 'name' => $by->name],
            'recorded_at' => $v->recorded_at->toIso8601String(), 'edited_by_doctor' => $v->edited_by_doctor,
            'reviewed_by_doctor_at' => $v->reviewed_by_doctor_at?->toIso8601String(),
        ];
    }
}
