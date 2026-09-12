<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

use App\Domain\Prescription\Data\Letterhead;

/**
 * PRESCRIPTION.md §7.2 — `pad_snapshot` → CSS. Every number the sheet needs (page box, margins, the blank band a
 * preprinted pad reserves, font sizes, section order, layout flags) is read from the frozen pad copy, never from
 * the live doctor_pad_settings row: a v1 print in 2036 must still be the geometry the doctor had in 2026.
 */
final readonly class PadGeometry
{
    public const SECTIONS = ['vitals', 'complaints', 'examination', 'diagnosis', 'rx', 'investigations', 'advice', 'followup', 'referral', 'signature'];

    /**
     * The ONLY place the pad's numeric bounds are written down. This class clamps to them on render, the pad
     * designer ships them to the browser as `limits` (PadDesignerController) and `UpdatePadSettingsRequest`
     * validates against them — so a non-UI caller cannot store a value the renderer would silently replace, and
     * the three can no longer drift apart. Ints, not floats: they travel to the client as JSON.
     *
     * @var array<string, array{min: int, max: int}>
     */
    public const LIMITS = [
        'margin_mm' => ['min' => 0, 'max' => 60],
        'header_height_mm' => ['min' => 0, 'max' => 120],
        'footer_height_mm' => ['min' => 0, 'max' => 80],
        'font_size_pt' => ['min' => 6, 'max' => 18],
        'rx_font_size_pt' => ['min' => 6, 'max' => 20],
    ];

    private const DEFAULT_MARGINS = ['top' => 20, 'right' => 15, 'bottom' => 20, 'left' => 15];

    /** Rounding slack between our exact millimetres and Chrome's page box — see `sheetMinHeightMm()`. */
    private const SHEET_SLACK_MM = 1.0;

    /** @param  array<string, mixed>  $pad */
    public function __construct(private array $pad, private RenderOptions $options) {}

    /** `@page { size: … }` — "A4 portrait". */
    public function pageSize(): string
    {
        return $this->options->paper.' '.$this->options->orientation;
    }

    /** @return array{top: int, right: int, bottom: int, left: int} mm */
    public function margins(): array
    {
        $margins = (array) ($this->pad['margins'] ?? []);
        $out = [];

        foreach (self::DEFAULT_MARGINS as $side => $default) {
            $value = $margins[$side] ?? $default;
            $out[$side] = max(self::LIMITS['margin_mm']['min'], min(self::LIMITS['margin_mm']['max'], is_numeric($value) ? (int) round((float) $value) : $default));
        }

        /** @var array{top: int, right: int, bottom: int, left: int} $out */
        return $out;
    }

    /** `@page { margin: … }` — "20mm 15mm 20mm 15mm". */
    public function marginCss(): string
    {
        $m = $this->margins();

        return "{$m['top']}mm {$m['right']}mm {$m['bottom']}mm {$m['left']}mm";
    }

    /**
     * The blank band a preprinted pad needs at the top of page 1: the doctor's pad is already printed there, so the
     * sheet must put nothing in it. Zero when preprinted mode is off (the letterhead or clinic block fills it).
     */
    public function headerHeightMm(): int
    {
        return max(self::LIMITS['header_height_mm']['min'], min(self::LIMITS['header_height_mm']['max'], (int) ($this->pad['header_height_mm'] ?? 35)));
    }

    public function footerHeightMm(): int
    {
        return max(self::LIMITS['footer_height_mm']['min'], min(self::LIMITS['footer_height_mm']['max'], (int) ($this->pad['footer_height_mm'] ?? 20)));
    }

    /** Reserved band the fixed footer occupies; preprinted pads keep their own footer area clear too. */
    public function reservedFooterMm(): int
    {
        return $this->options->preprinted ? $this->footerHeightMm() : 0;
    }

    /** Paper geometry in mm, orientation applied — A4 210×297, A5 148×210 (ISO 216). */
    public function paperWidthMm(): float
    {
        return $this->options->orientation === 'landscape' ? $this->longEdge() : $this->shortEdge();
    }

    public function paperHeightMm(): float
    {
        return $this->options->orientation === 'landscape' ? $this->shortEdge() : $this->longEdge();
    }

    /**
     * Height of the printable box = paper minus the pad's top/bottom margins. The sheet uses it as a `min-height`
     * so a short prescription still puts the signature block at the bottom of page one, exactly where a doctor
     * signs on a real pad, instead of leaving it floating under the last advice line.
     */
    public function contentHeightMm(): float
    {
        $m = $this->margins();

        return max(20.0, $this->paperHeightMm() - $m['top'] - $m['bottom']);
    }

    /**
     * What `.sheet` may actually claim as `min-height`, and the difference between a one-page prescription and a
     * two-page one.
     *
     * `contentHeightMm()` is the printable box in exact ISO 216 millimetres. The box Chrome lays out is not: the
     * paper size arrives as a rounded *inch* figure (Puppeteer's A4 is 8.27 × 11.7 in, A5 5.83 × 8.27 in) and the
     * result is converted to CSS pixels, so the real printable height lands a fraction of a millimetre either side
     * of ours. A `min-height` of exactly the printable height therefore rounds *past* it about half the time, the
     * flex column overflows by a sub-pixel, and Chrome opens a second page to hold a footer that fits perfectly
     * well on the first — which is precisely the empty second sheet of signature and QR that came out of the
     * printer. One millimetre of slack is invisible on paper and removes the coin toss.
     */
    public function sheetMinHeightMm(): float
    {
        return max(20.0, $this->contentHeightMm() - self::SHEET_SLACK_MM);
    }

    public function contentWidthMm(): float
    {
        $m = $this->margins();

        return max(20.0, $this->paperWidthMm() - $m['left'] - $m['right']);
    }

    /**
     * The pad's font family is interpolated straight into the stylesheet, and it is user input frozen years ago.
     * Anything that is not plainly a font name (SCHEMA varchar(64)) is rejected outright rather than scrubbed:
     * a "sanitised" `evil body displaynone x` is not a font either, and the default always renders Bangla.
     */
    public function fontFamily(): string
    {
        $family = trim((string) ($this->pad['font_family'] ?? ''));

        return preg_match('/^[A-Za-z0-9][A-Za-z0-9 \-]{0,63}$/', $family) === 1 ? $family : 'Noto Sans Bengali';
    }

    public function fontSizePt(): float
    {
        $size = (float) ($this->pad['font_size_pt'] ?? 10.5);

        return $size >= self::LIMITS['font_size_pt']['min'] && $size <= self::LIMITS['font_size_pt']['max'] ? $size : 10.5;
    }

    public function rxFontSizePt(): float
    {
        $size = $this->layout()['rx_font_size_pt'] ?? null;

        return is_numeric($size) && (float) $size >= self::LIMITS['rx_font_size_pt']['min'] && (float) $size <= self::LIMITS['rx_font_size_pt']['max'] ? (float) $size : $this->fontSizePt() + 0.5;
    }

    public function columns(): int
    {
        return ((int) ($this->layout()['columns'] ?? 1)) === 2 ? 2 : 1;
    }

    public function showQr(): bool
    {
        return (bool) ($this->pad['show_qr'] ?? true);
    }

    public function showVitals(): bool
    {
        return (bool) ($this->pad['show_vitals'] ?? true) && $this->section('vitals');
    }

    public function showDrugInfoUrl(): bool
    {
        return (bool) ($this->pad['show_drug_info_url'] ?? true);
    }

    /** `layout.flags.{icd_codes,investigation_prices,generic_names}` — absent flags default to true (SCHEMA §3.1). */
    public function flag(string $key): bool
    {
        $flags = (array) ($this->layout()['flags'] ?? []);

        return (bool) ($flags[$key] ?? true);
    }

    /** `layout.sections[]` visibility; an unlisted section prints (the designer only stores what it knows). */
    public function section(string $key): bool
    {
        foreach ((array) ($this->layout()['sections'] ?? []) as $section) {
            if (is_array($section) && ($section['key'] ?? null) === $key) {
                return (bool) ($section['visible'] ?? true);
            }
        }

        return true;
    }

    /**
     * Section keys in the doctor's own order, visible ones only.
     *
     * @return list<string>
     */
    public function orderedSections(): array
    {
        $ordered = [];

        foreach ((array) ($this->layout()['sections'] ?? []) as $section) {
            $key = is_array($section) ? (string) ($section['key'] ?? '') : '';

            if (in_array($key, self::SECTIONS, true) && (bool) ($section['visible'] ?? true)) {
                $ordered[] = $key;
            }
        }

        return $ordered === [] ? self::SECTIONS : array_values(array_unique($ordered));
    }

    /**
     * The pad's structured letterhead (§7.2): the frozen `pad.letterhead` when the doctor designed one, otherwise
     * the fallback built from this same snapshot's clinic and doctor blocks — so a prescription issued before the
     * column existed prints the header it always printed, still without a single model read (I6).
     *
     * @param  array<string, mixed>  $clinic  snapshot.clinic
     * @param  array<string, mixed>  $doctor  snapshot.doctor
     */
    public function letterhead(array $clinic = [], array $doctor = []): Letterhead
    {
        $letterhead = Letterhead::fromArray($this->pad['letterhead'] ?? null);

        return $letterhead->isEmpty() ? Letterhead::fromSnapshot($clinic, $doctor) : $letterhead;
    }

    /**
     * @deprecated The free-HTML letterhead is no longer rendered by any print path — `letterhead()` replaced it.
     *             The columns stay on `doctor_pad_settings` (and in every frozen `pad_snapshot`) for one release
     *             so nothing is lost while doctors migrate; these accessors exist only for that data.
     */
    public function headerHtml(): ?string
    {
        $html = $this->pad['header_html_inlined'] ?? $this->pad['header_html'] ?? null;

        return is_string($html) && trim($html) !== '' ? $html : null;
    }

    /** @deprecated See `headerHtml()`. */
    public function footerHtml(): ?string
    {
        $html = $this->pad['footer_html'] ?? null;

        return is_string($html) && trim($html) !== '' ? $html : null;
    }

    private function shortEdge(): float
    {
        return $this->options->paper === 'A5' ? 148.0 : 210.0;
    }

    private function longEdge(): float
    {
        return $this->options->paper === 'A5' ? 210.0 : 297.0;
    }

    /** @return array<string, mixed> */
    private function layout(): array
    {
        return (array) ($this->pad['layout'] ?? []);
    }
}
