<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Services;

use App\Domain\Prescription\Data\PrescriptionSnapshot;
use App\Domain\Prescription\Render\RenderOptions;
use App\Domain\Prescription\Services\SnapshotBuilder;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;

/**
 * BRIEF §5.A "print a test page": the sheet a doctor feeds a physical preprinted pad through to check the blank band
 * lines up. It is deliberately NOT a mock-up of the pad — it is the real
 * `PrescriptionRenderer` + `PadGeometry` + `resources/views/print/prescription/**` pipeline, fed a sample document
 * whose `pad` key is the doctor's live `doctor_pad_settings` row rendered by the same
 * `SnapshotBuilder::padArray()` that freezes `pad_snapshot` at issue. What comes out of the printer here is
 * therefore the same geometry a real prescription prints with — that is the whole point of the button.
 *
 * The sheet carries the DRAFT watermark: a test page must never be mistakable for a prescription.
 */
final class PadTestSheet
{
    public function __construct(private readonly SnapshotBuilder $snapshots) {}

    public function options(DoctorPadSetting $pad, ?string $language = null): RenderOptions
    {
        return RenderOptions::fromPad($this->snapshots->padArray($pad), purpose: 'print', language: $language)
            ->withWatermark('DRAFT');
    }

    public function snapshot(Doctor $doctor, DoctorPadSetting $pad): PrescriptionSnapshot
    {
        $doctor->loadMissing(['profile', 'specialties']);
        $tenant = Tenancy::current();
        $branding = (array) ($tenant !== null ? $tenant->branding : []);
        $profile = $doctor->profile;
        $today = Clock::today()->toDateString();

        return new PrescriptionSnapshot([
            'schema' => 1,
            'prescription' => [
                'public_id' => 'PADTEST', 'version' => 1, 'verification_code' => 'SAMPLE',
                'issued_at' => Clock::now()->toIso8601String(), 'language' => $this->snapshots->padArray($pad)['default_language'],
                'verify_url' => null, 'status_at_issue' => 'draft', 'mode' => 'structured',
            ],
            'clinic' => [
                'name' => (string) ($tenant !== null ? $tenant->name : ''),
                'name_bn' => isset($branding['name_bn']) ? (string) $branding['name_bn'] : null,
                'branch' => ['name' => (string) __('clinic.pad.sample.branch'), 'address' => (string) __('clinic.pad.sample.address'), 'phone' => '+8801700000000'],
                'logo_data_uri' => null,
            ],
            'doctor' => [
                'name' => $doctor->name, 'name_bn' => $doctor->name_bn, 'degrees' => $profile?->degrees, 'degrees_bn' => $profile?->degrees_bn,
                'bmdc_reg_no' => $profile?->bmdc_reg_no, 'designation' => $profile?->designation,
                'specialties' => $doctor->specialties->map(fn ($s) => (string) $s->name)->values()->all(),
                'signature_path' => $pad->signature_path, 'signature_data_uri' => null,
            ],
            'patient' => [
                'public_id' => 'SAMPLE', 'patient_code' => 'P-000000', 'name' => (string) __('clinic.pad.sample.patient'),
                'age_text' => '32 y', 'gender' => 'male', 'mobile_masked' => '', 'weight_kg' => 62,
            ],
            'visit' => [
                'public_id' => 'SAMPLE', 'date' => $today, 'serial' => 'A-001', 'type' => 'new',
                'chief_complaints' => [[
                    'text' => (string) __('clinic.pad.sample.complaint', [], 'en'), 'text_bn' => (string) __('clinic.pad.sample.complaint', [], 'bn'),
                    'duration_label' => ['bn' => (string) __('clinic.pad.sample.duration', [], 'bn'), 'en' => (string) __('clinic.pad.sample.duration', [], 'en')],
                ]],
                'examination_findings' => (string) __('clinic.pad.sample.examination'),
                'diagnoses' => [['title' => (string) __('clinic.pad.sample.diagnosis', [], 'en'), 'icd10_code' => 'J06.9', 'kind' => 'confirmed']],
                'vitals' => ['bp_systolic' => 120, 'bp_diastolic' => 80, 'pulse_bpm' => 78, 'temperature_c' => '38.2', 'weight_kg' => 62, 'recorded_at' => Clock::now()->toIso8601String()],
            ],
            'items' => [
                $this->item(1, 'Napa', 'Paracetamol', '500 mg', 'Tablet', '১+১+১ · ৫ দিন', '1+1+1 · 5 days', '15', (string) __('clinic.pad.sample.after_meal')),
                $this->item(2, 'Fexo', 'Fexofenadine', '120 mg', 'Tablet', '০+০+১ · ৭ দিন', '0+0+1 · 7 days', '7', null),
            ],
            'investigations' => [['name' => 'CBC with ESR', 'name_bn' => null, 'price_paisa' => 45000, 'external_centre' => null, 'referral_note' => null, 'is_urgent' => false]],
            'investigations_total_paisa' => 45000,
            'advice' => [['text' => (string) __('clinic.pad.sample.advice', [], 'en'), 'text_bn' => (string) __('clinic.pad.sample.advice', [], 'bn')]],
            'referrals' => [],
            'follow_up' => [
                'on' => Clock::today()->addDays(7)->toDateString(), 'note' => null, 'days' => 7,
                'label' => ['bn' => (string) __('clinic.pad.sample.follow_up', [], 'bn'), 'en' => (string) __('clinic.pad.sample.follow_up', [], 'en')],
            ],
            'handwriting_pages' => [],
            'drawing_json' => [],
            'pad' => $this->snapshots->padArray($pad) + ['header_html_inlined' => $pad->header_html],
            'allergies' => [],
            'safety' => ['alerts' => [], 'overrides' => []],
            'qr' => ['url' => null, 'svg_data_uri' => null],
            'rendered_by' => ['app_version' => (string) config('app.version', '1.0.0'), 'template_version' => SnapshotBuilder::TEMPLATE_VERSION],
        ]);
    }

    /** @return array<string, mixed> */
    private function item(int $sort, string $brand, string $generic, string $strength, string $form, string $bn, string $en, string $quantity, ?string $instruction): array
    {
        return [
            'sort' => $sort, 'generic_name' => $generic, 'brand_name' => $brand, 'strength' => $strength, 'form' => $form,
            'route' => 'oral', 'dose_schedule' => $en, 'dose_json' => [], 'duration_text' => null,
            'quantity' => $quantity, 'quantity_unit' => 'pcs', 'timing' => 'after_meal',
            'instruction' => $instruction, 'instruction_bn' => null, 'info_url' => null, 'is_continued' => false,
            'display' => ['bn' => ['interpretation' => $bn, 'quantity' => $quantity], 'en' => ['interpretation' => $en, 'quantity' => $quantity]],
        ];
    }
}
