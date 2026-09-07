<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Prescription\Data\ParsedLine;
use App\Domain\Prescription\Render\QrCodeRenderer;
use App\Domain\Prescription\Safety\SafetyReport;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionAdvice;
use App\Models\Tenant\PrescriptionInvestigation;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\PrescriptionReferral;
use App\Models\Tenant\Vital;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Storage;

/**
 * Builds prescriptions.snapshot (PRESCRIPTION.md §6.2 = SCHEMA §5.3.2 + the ＋ keys): everything the templates print,
 * pre-resolved text, both-language display strings, pad copy, QR data URI. Rendering afterwards needs the row and
 * nothing else (I6). `preview()` builds the same document transiently for a draft (watermark DRAFT, P3).
 */
final class SnapshotBuilder
{
    public const TEMPLATE_VERSION = 'print.prescription.v1';

    public function __construct(private readonly DisplayFormatter $display, private readonly HandwritingStorage $files) {}

    /**
     * @param  array<string, mixed>  $safety  {alerts: [...], overrides: [...]} — the final alert set + overrides at issue
     * @return array<string, mixed>
     */
    public function build(Prescription $rx, DoctorPadSetting $pad, string $catalogVersion, array $safety = ['alerts' => [], 'overrides' => []], ?string $verificationCode = null): array
    {
        $rx->loadMissing(['items', 'investigations', 'advice', 'referrals', 'visit.serial', 'visit.latestVitals.recordedBy', 'patient.allergies', 'doctor.profile', 'doctor.specialties', 'branch']);
        $visit = $rx->visit;
        $patient = $rx->patient;
        $doctor = $rx->doctor;
        $profile = $doctor->profile;
        $branch = $rx->branch;
        $tenant = Tenancy::current();
        $branding = (array) ($tenant !== null ? $tenant->branding : []);
        $code = $verificationCode ?? $rx->verification_code ?? 'DRAFT';
        $verifyUrl = VerificationUrl::for($code);
        $padArray = $this->padArray($pad);
        $handwritingPages = $this->files->handwritingPages($rx);
        $rootPublicId = $rx->root_prescription_id !== null && $rx->root_prescription_id !== $rx->id ? Prescription::query()->whereKey($rx->root_prescription_id)->value('public_id') : $rx->public_id;
        $supersedesPublicId = $rx->supersedes_prescription_id !== null ? Prescription::query()->whereKey($rx->supersedes_prescription_id)->value('public_id') : null;
        $vitals = $visit->latestVitals;
        $followUpDays = $visit->follow_up_on !== null ? (int) Clock::today()->diffInDays($visit->follow_up_on, false) : null;
        $language = $rx->language->value;
        $investigations = $rx->investigations->map(fn (PrescriptionInvestigation $x) => [
            'name' => $x->name, 'name_bn' => $x->name_bn, 'price_paisa' => $x->price_paisa,
            'external_centre' => $x->external_diagnostic_centre_id !== null ? ($x->externalCentre?->name) : null,
            'referral_note' => $x->referral_note, 'is_urgent' => $x->is_urgent,
        ])->values()->all();

        return [
            'schema' => 1,
            'prescription' => [
                'public_id' => $rx->public_id, 'version' => $rx->version, 'verification_code' => $code,
                'issued_at' => ($rx->issued_at ?? now())->toIso8601String(), 'language' => $language, 'verify_url' => $verifyUrl,
                'id' => $rx->id, 'root_id' => $rx->root_prescription_id ?? $rx->id, 'root_public_id' => $rootPublicId,
                'supersedes_id' => $rx->supersedes_prescription_id, 'supersedes_public_id' => $supersedesPublicId,
                'status_at_issue' => $rx->isDraft() ? 'draft' : 'issued', 'mode' => $handwritingPages !== [] ? 'handwriting' : 'structured',
                'catalog_version' => $catalogVersion, 'tenant_id' => $rx->tenant_id, 'visit_id' => $rx->visit_id, 'amend_reason' => $rx->amend_reason,
            ],
            'clinic' => [
                'name' => (string) ($tenant !== null ? $tenant->name : ''), 'name_bn' => isset($branding['name_bn']) ? (string) $branding['name_bn'] : null,
                'branch' => ['name' => $branch->name, 'address' => $branch->address, 'phone' => $branch->phone],
                'logo_data_uri' => $this->dataUri($pad->logo_path ?? (isset($branding['logo_path']) ? (string) $branding['logo_path'] : null)),
            ],
            'doctor' => [
                'name' => $doctor->name, 'name_bn' => $doctor->name_bn, 'degrees' => $profile?->degrees, 'degrees_bn' => $profile?->degrees_bn,
                'bmdc_reg_no' => $profile?->bmdc_reg_no, 'designation' => $profile?->designation,
                'specialties' => $doctor->specialties->map(fn ($s) => (string) $s->name)->values()->all(),
                'signature_path' => $pad->signature_path, 'signature_data_uri' => $this->dataUri($pad->signature_path), 'id' => $doctor->id, 'public_id' => $doctor->public_id,
            ],
            'patient' => [
                'public_id' => $patient->public_id, 'patient_code' => $patient->patient_code, 'name' => $patient->name, 'age_text' => $patient->age_text,
                'gender' => $patient->gender?->value, 'mobile_masked' => self::maskMobile($patient->mobile),
                'weight_kg' => $vitals?->weight_kg, 'age_months' => $patient->age_months, 'id' => $patient->id, 'dob' => $patient->dob?->toDateString(),
            ],
            'visit' => [
                'public_id' => $visit->public_id, 'date' => $visit->started_at->setTimezone(Clock::timezone())->toDateString(), 'serial' => $visit->serial?->display_code, 'type' => $visit->type->value,
                'chief_complaints' => array_values(array_map(fn ($c) => $c + ['duration_label' => $this->display->complaintDuration($c['duration'] ?? null)], $visit->chief_complaints)),
                'examination_findings' => $visit->examination_findings,
                'diagnoses' => array_values($visit->diagnoses),
                'vitals' => $vitals === null ? null : $this->vitals($vitals),
            ],
            'items' => $rx->items->map(fn (PrescriptionItem $i) => $this->item($i, $language))->values()->all(),
            'investigations' => $investigations,
            'investigations_total_paisa' => (int) array_sum(array_map(fn ($x) => (int) ($x['price_paisa'] ?? 0), $investigations)),
            'advice' => $rx->advice->map(fn (PrescriptionAdvice $a) => ['text' => $a->text, 'text_bn' => $a->text_bn])->values()->all(),
            'referrals' => $rx->referrals->map(fn (PrescriptionReferral $r) => ['type' => $r->type->value, 'to' => $r->referred_to_name, 'specialty' => $r->referred_to_specialty, 'note' => $r->note, 'is_urgent' => $r->is_urgent])->values()->all(),
            'follow_up' => ['on' => $visit->follow_up_on?->toDateString(), 'note' => $visit->follow_up_note, 'days' => $followUpDays, 'label' => $this->display->followUp($followUpDays)],
            'handwriting_image_path' => $rx->handwriting_image_path,
            'handwriting_pages' => $handwritingPages,
            'drawing_image_path' => $rx->drawing_image_path,
            'drawing_json' => $rx->drawing_json,
            'pad' => $padArray + ['header_html_inlined' => $pad->header_html],
            'allergies' => $patient->allergies->filter(fn ($a) => $a->is_active)->map(fn ($a) => (string) $a->allergen_name)->values()->all(),
            'safety' => ['alerts' => array_values($safety['alerts'] ?? []), 'overrides' => array_values($safety['overrides'] ?? [])],
            'qr' => ['url' => $verifyUrl, 'svg_data_uri' => QrCodeRenderer::svgDataUri($verifyUrl)],
            'rendered_by' => ['app_version' => (string) config('app.version', '1.0.0'), 'template_version' => self::TEMPLATE_VERSION, 'keywords_version' => (string) config('prescription.cheat_sheet_version', '1')],
        ];
    }

    /**
     * Transient document for a draft preview (watermark DRAFT; nothing persisted).
     *
     * @return array<string, mixed>
     */
    public function preview(Prescription $rx, DoctorPadSetting $pad, string $catalogVersion, ?SafetyReport $report = null): array
    {
        return $this->build($rx, $pad, $catalogVersion, ['alerts' => $report?->alertsArray() ?? [], 'overrides' => []], 'DRAFT');
    }

    /** @return array<string, mixed> */
    private function item(PrescriptionItem $i, string $language): array
    {
        $parsed = $i->dose_json !== [] ? ParsedLine::fromArray($i->dose_json) : null;
        $display = $parsed !== null ? $this->display->item($parsed) : ['bn' => null, 'en' => null];

        return [
            'sort' => $i->sort_order, 'generic_name' => $i->generic_name, 'brand_name' => $i->brand_name, 'strength' => $i->strength, 'form' => $i->form, 'route' => $i->route,
            'dose_schedule' => $i->dose_schedule, 'dose_json' => $i->dose_json, 'duration_text' => $i->duration_text, 'quantity' => $i->quantity, 'quantity_unit' => $i->quantity_unit,
            'timing' => $i->timing->value, 'instruction' => $i->instruction, 'instruction_bn' => $i->instruction_bn,
            'info_url' => VerificationUrl::drugInfo($i->info_url_slug),
            'generic_id' => $i->generic_id, 'brand_id' => $i->brand_id, 'strength_id' => $i->strength_id, 'custom_brand_id' => $i->custom_brand_id, 'is_continued' => $i->is_continued,
            'display' => $display,
        ];
    }

    /** @return array<string, mixed> */
    private function vitals(Vital $v): array
    {
        return [
            'bp_systolic' => $v->bp_systolic, 'bp_diastolic' => $v->bp_diastolic, 'pulse_bpm' => $v->pulse_bpm, 'temperature_c' => $v->temperature_c,
            'spo2_percent' => $v->spo2_percent, 'respiratory_rate' => $v->respiratory_rate, 'weight_kg' => $v->weight_kg, 'height_cm' => $v->height_cm, 'bmi' => $v->bmi,
            'blood_glucose_mgdl' => $v->blood_glucose_mgdl, 'recorded_at' => $v->recorded_at->toIso8601String(),
            'recorded_by' => $v->recordedBy?->name, 'reviewed_by_doctor_at' => $v->reviewed_by_doctor_at?->toIso8601String(),
        ];
    }

    /**
     * The doctor_pad_settings row as pad_snapshot (SCHEMA §3.4).
     *
     * @return array<string, mixed>
     */
    public function padArray(DoctorPadSetting $pad): array
    {
        return [
            'paper_size' => $pad->paper_size->value, 'orientation' => $pad->orientation->value, 'letterhead_enabled' => $pad->letterhead_enabled,
            'preprinted_mode' => $pad->preprinted_mode, 'logo_path' => $pad->logo_path, 'header_html' => $pad->header_html, 'footer_html' => $pad->footer_html,
            'margins' => $pad->margins, 'header_height_mm' => $pad->header_height_mm, 'footer_height_mm' => $pad->footer_height_mm,
            'font_family' => $pad->font_family, 'font_size_pt' => (float) $pad->font_size_pt, 'show_qr' => $pad->show_qr, 'show_vitals' => $pad->show_vitals,
            'show_drug_info_url' => $pad->show_drug_info_url, 'layout' => $pad->layout, 'signature_path' => $pad->signature_path,
            'default_language' => (string) $pad->default_language,
        ];
    }

    public static function maskMobile(?string $mobile): string
    {
        if ($mobile === null || strlen($mobile) < 6) {
            return '';
        }

        $local = str_starts_with($mobile, '+88') ? substr($mobile, 3) : $mobile;

        return substr($local, 0, 3).str_repeat('*', max(0, strlen($local) - 5)).substr($local, -2);
    }

    private function dataUri(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        try {
            $disk = Storage::disk((string) config('prescription.uploads_disk', 'uploads'));

            if (! $disk->exists($path)) {
                return null;
            }

            $bytes = $disk->get($path);
            $mime = $disk->mimeType($path) ?: 'image/png';

            return $bytes === null || strlen($bytes) > 2_000_000 ? null : "data:{$mime};base64,".base64_encode($bytes);
        } catch (\Throwable) {
            return null;
        }
    }
}
