<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Domain\Prescription\Data\Letterhead;
use App\Domain\Prescription\Render\PadGeometry;
use App\Models\Tenant\DoctorPadSetting;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The pad designer's own shape. Every key here is one the print pipeline reads back through `PadGeometry`
 * (PRESCRIPTION.md §7.2), so the designer form and the renderer can never drift apart.
 *
 * @mixin DoctorPadSetting
 */
final class PadSettingResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'paper_size' => $this->paper_size->value,
            'orientation' => $this->orientation->value,
            'letterhead_enabled' => $this->letterhead_enabled,
            'preprinted_mode' => $this->preprinted_mode,
            'logo_path' => $this->logo_path,
            'header_html' => $this->header_html,
            'footer_html' => $this->footer_html,
            'margins' => [
                'top' => (int) ($this->margins['top'] ?? 20),
                'right' => (int) ($this->margins['right'] ?? 15),
                'bottom' => (int) ($this->margins['bottom'] ?? 20),
                'left' => (int) ($this->margins['left'] ?? 15),
            ],
            'header_height_mm' => $this->header_height_mm,
            'footer_height_mm' => $this->footer_height_mm,
            'font_family' => $this->font_family,
            'font_size_pt' => (float) $this->font_size_pt,
            'show_qr' => $this->show_qr,
            'show_vitals' => $this->show_vitals,
            'show_drug_info_url' => $this->show_drug_info_url,
            'layout' => [
                'sections' => $this->sections(),
                'columns' => ((int) ($this->layout['columns'] ?? 1)) === 2 ? 2 : 1,
                'rx_font_size_pt' => isset($this->layout['rx_font_size_pt']) && is_numeric($this->layout['rx_font_size_pt']) ? (float) $this->layout['rx_font_size_pt'] : null,
                'flags' => [
                    'icd_codes' => (bool) ($this->layout['flags']['icd_codes'] ?? true),
                    'investigation_prices' => (bool) ($this->layout['flags']['investigation_prices'] ?? true),
                    'generic_names' => (bool) ($this->layout['flags']['generic_names'] ?? true),
                ],
            ],
            // Always the full contract shape, never the raw column, and normalised through the very DTO the print
            // partials render from: a pad row written before the letterhead existed (or by an older client) still
            // reaches the designer as something it can draw, and as exactly what it will print.
            'letterhead' => Letterhead::fromArray($this->letterhead)->toArray(),
            'token_slip_template' => $this->token_slip_template->value,
            'default_language' => $this->default_language,
            'signature_path' => $this->signature_path,
            'sample_path' => $this->sample_path,
        ];
    }

    /**
     * Section order/visibility, always the full ten in a stable order: the designer needs a row per section even
     * for one the stored JSON has never mentioned (PadGeometry treats an unlisted section as visible).
     *
     * @return array<int, array{key: string, visible: bool}>
     */
    private function sections(): array
    {
        $stored = [];
        $order = [];

        foreach ((array) ($this->layout['sections'] ?? []) as $section) {
            if (is_array($section) && is_string($section['key'] ?? null)) {
                $stored[$section['key']] = (bool) ($section['visible'] ?? true);
                $order[] = $section['key'];
            }
        }

        $keys = array_values(array_unique(array_merge(
            array_values(array_filter($order, fn (string $k) => in_array($k, PadGeometry::SECTIONS, true))),
            PadGeometry::SECTIONS,
        )));

        return array_map(fn (string $key) => ['key' => $key, 'visible' => $stored[$key] ?? true], $keys);
    }
}
