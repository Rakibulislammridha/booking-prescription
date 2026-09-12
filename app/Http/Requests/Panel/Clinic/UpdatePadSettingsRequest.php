<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\PadSettingsData;
use App\Domain\Clinic\Enums\PadOrientation;
use App\Domain\Clinic\Enums\PadPaperSize;
use App\Domain\Clinic\Enums\TokenSlipTemplate;
use App\Domain\Prescription\Data\Letterhead;
use App\Domain\Prescription\Data\LetterheadColumn;
use App\Domain\Prescription\Data\LetterheadLine;
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
    /** `#RRGGBB`, the only colour form the print CSS and the designer's colour input both speak. */
    private const HEX = 'regex:/^#[0-9A-Fa-f]{6}$/';

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
            'sample_path' => ['nullable', 'string', 'max:255'],
            'letterhead' => ['sometimes', 'array:accent_color,text_color,muted_color,header,footer'],
            // The block is whole-object or absent: a partial save would leave the print partials guessing which
            // half of a letterhead they were looking at. Only when it is sent do its own rules apply — the
            // designer PUTs every field it owns, and every other caller keeps working untouched.
            ...($this->has('letterhead') ? self::letterheadRules() : []),
        ];
    }

    /**
     * The structured letterhead (BRIEF §5.A). Every bound here is a constant of the DTOs the PRINT path renders
     * from — `Letterhead`, `LetterheadColumn`, `LetterheadLine` — never a number typed in this file, so the editor,
     * the validator and the renderer cannot drift apart. Text is plain: `prepareForValidation()` has already run
     * the DTO's own `strip_tags` over it, and these rules measure the stripped length, not the pasted markup's.
     *
     * @return array<string, array<int, mixed>>
     */
    private static function letterheadRules(): array
    {
        $line = fn (string $prefix): array => [
            $prefix.'.text' => ['required', 'string', 'max:'.LetterheadLine::MAX_TEXT],
            $prefix.'.text_bn' => ['nullable', 'string', 'max:'.LetterheadLine::MAX_TEXT],
            $prefix.'.color' => ['required', Rule::in(LetterheadLine::COLORS)],
            $prefix.'.weight' => ['required', Rule::in(LetterheadLine::WEIGHTS)],
            $prefix.'.size' => ['required', 'numeric', 'between:'.LetterheadLine::SIZE_MIN.','.LetterheadLine::SIZE_MAX],
            $prefix.'.transform' => ['required', Rule::in(LetterheadLine::TRANSFORMS)],
            $prefix.'.align' => ['nullable', Rule::in(LetterheadLine::ALIGNS)],
        ];

        return [
            'letterhead.accent_color' => ['required', 'string', self::HEX],
            'letterhead.text_color' => ['required', 'string', self::HEX],
            'letterhead.muted_color' => ['required', 'string', self::HEX],
            'letterhead.header' => ['required', 'array:align,lines,rule'],
            'letterhead.header.align' => ['required', Rule::in(Letterhead::HEADER_ALIGNS)],
            'letterhead.header.rule' => ['required', 'boolean'],
            'letterhead.header.lines' => ['present', 'array', 'max:'.Letterhead::MAX_LINES],
            ...$line('letterhead.header.lines.*'),
            'letterhead.footer' => ['required', 'array:columns,rule'],
            'letterhead.footer.rule' => ['required', 'boolean'],
            'letterhead.footer.columns' => ['present', 'array', 'max:'.Letterhead::MAX_COLUMNS],
            'letterhead.footer.columns.*' => ['array:align,logo,lines'],
            'letterhead.footer.columns.*.align' => ['required', Rule::in(LetterheadColumn::ALIGNS)],
            'letterhead.footer.columns.*.logo' => ['required', 'boolean'],
            'letterhead.footer.columns.*.lines' => ['present', 'array', 'max:'.Letterhead::MAX_LINES],
            ...$line('letterhead.footer.columns.*.lines.*'),
        ];
    }

    /**
     * Tags never reach the column. `LetterheadLine::text()` is the DTO's own cleaner, run here as well as on the
     * way out of the column, so the LENGTH rules above measure the text a doctor will actually see printed and a
     * pasted `<b>` is simply dropped instead of failing a save.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('letterhead')) {
            $this->merge(['letterhead' => self::sanitize($this->input('letterhead'))]);
        }
    }

    /** Only the text fields are touched: a bad colour or a 21st line still reaches the validator as an error. */
    private static function sanitize(mixed $letterhead): mixed
    {
        if (! is_array($letterhead)) {
            return $letterhead;
        }

        if (is_array($letterhead['header'] ?? null) && is_array($letterhead['header']['lines'] ?? null)) {
            $letterhead['header']['lines'] = array_map(self::sanitizeLine(...), $letterhead['header']['lines']);
        }

        if (is_array($letterhead['footer'] ?? null) && is_array($letterhead['footer']['columns'] ?? null)) {
            $letterhead['footer']['columns'] = array_map(function (mixed $column): mixed {
                if (is_array($column) && is_array($column['lines'] ?? null)) {
                    $column['lines'] = array_map(self::sanitizeLine(...), $column['lines']);
                }

                return $column;
            }, $letterhead['footer']['columns']);
        }

        return $letterhead;
    }

    private static function sanitizeLine(mixed $line): mixed
    {
        if (! is_array($line)) {
            return $line;
        }

        if (array_key_exists('text', $line)) {
            $line['text'] = LetterheadLine::text($line['text']) ?? '';
        }

        if (array_key_exists('text_bn', $line)) {
            $line['text_bn'] = LetterheadLine::text($line['text_bn']);
        }

        return $line;
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
