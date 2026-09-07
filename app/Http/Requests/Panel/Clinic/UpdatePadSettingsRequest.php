<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\PadSettingsData;
use App\Domain\Clinic\Enums\PadOrientation;
use App\Domain\Clinic\Enums\PadPaperSize;
use App\Domain\Clinic\Enums\TokenSlipTemplate;
use App\Domain\Prescription\Render\PadGeometry;
use App\Models\Tenant\Doctor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Every numeric bound here is `PadGeometry::LIMITS` — the clamps the renderer actually applies (PRESCRIPTION.md
 * §7.2). The column is wider than the renderer honours in places (footer band: smallint vs 80 mm), so validating
 * against the column would let an API caller store a value that silently changes the first time the pad is
 * printed. The designer receives the same array as `limits`, so all three agree by construction.
 */
final class UpdatePadSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        $doctor = $this->route('doctor');

        return $doctor instanceof Doctor && ($this->user('web')?->can('designPad', $doctor) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'paper_size' => ['sometimes', Rule::enum(PadPaperSize::class)],
            'orientation' => ['sometimes', Rule::enum(PadOrientation::class)],
            'letterhead_enabled' => ['sometimes', 'boolean'],
            'preprinted_mode' => ['sometimes', 'boolean'],
            'logo_path' => ['nullable', 'string', 'max:255'],
            'header_html' => ['nullable', 'string', 'max:20000'],
            'footer_html' => ['nullable', 'string', 'max:20000'],
            'margins' => ['sometimes', 'array:top,right,bottom,left'],
            'margins.*' => ['integer', self::between('margin_mm')],
            'header_height_mm' => ['sometimes', 'integer', self::between('header_height_mm')],
            'footer_height_mm' => ['sometimes', 'integer', self::between('footer_height_mm')],
            'font_family' => ['sometimes', 'string', 'max:64'],
            'font_size_pt' => ['sometimes', 'numeric', self::between('font_size_pt')],
            'show_qr' => ['sometimes', 'boolean'],
            'show_vitals' => ['sometimes', 'boolean'],
            'show_drug_info_url' => ['sometimes', 'boolean'],
            'layout' => ['sometimes', 'array'],
            'layout.sections' => ['sometimes', 'array'],
            'layout.sections.*.key' => ['required', Rule::in(PadGeometry::SECTIONS)],
            'layout.sections.*.visible' => ['required', 'boolean'],
            'layout.columns' => ['sometimes', 'integer', Rule::in([1, 2])],
            'layout.rx_font_size_pt' => ['nullable', 'numeric', self::between('rx_font_size_pt')],
            'layout.flags' => ['sometimes', 'array:icd_codes,investigation_prices,generic_names'],
            'token_slip_template' => ['sometimes', Rule::enum(TokenSlipTemplate::class)],
            'default_language' => ['sometimes', Rule::in(['bn', 'en', 'both'])],
            'signature_path' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function toData(): PadSettingsData
    {
        return PadSettingsData::fromRequest($this);
    }

    /** `between:min,max` straight off the renderer's clamps — never a number typed here. */
    private static function between(string $key): string
    {
        $limit = PadGeometry::LIMITS[$key] ?? ['min' => 0, 'max' => 0];

        return "between:{$limit['min']},{$limit['max']}";
    }
}
