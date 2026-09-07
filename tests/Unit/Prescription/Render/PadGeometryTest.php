<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Render;

use App\Domain\Prescription\Render\PadGeometry;
use App\Domain\Prescription\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

/**
 * PRESCRIPTION.md §7.2 — pad_snapshot → CSS. Prescriptions print on preprinted pads with fixed margins, so these
 * numbers are the difference between a usable sheet and one that overprints the clinic's own letterhead.
 */
final class PadGeometryTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pad(array $overrides = []): array
    {
        return $overrides + [
            'paper_size' => 'A4', 'orientation' => 'portrait', 'letterhead_enabled' => true, 'preprinted_mode' => false,
            'margins' => ['top' => 20, 'right' => 15, 'bottom' => 20, 'left' => 15], 'header_height_mm' => 35,
            'footer_height_mm' => 20, 'font_family' => 'Noto Sans Bengali', 'font_size_pt' => 10.5,
            'show_qr' => true, 'show_vitals' => true, 'show_drug_info_url' => true, 'default_language' => 'both',
            'layout' => ['sections' => [], 'columns' => 1, 'rx_font_size_pt' => null, 'flags' => []],
        ];
    }

    /**
     * @param  array<string, mixed>  $padOverrides
     * @param  array<string, mixed>  $queryOverrides
     */
    private function geometry(array $padOverrides = [], array $queryOverrides = []): PadGeometry
    {
        $pad = $this->pad($padOverrides);

        return new PadGeometry($pad, RenderOptions::fromPad($pad)->override($queryOverrides));
    }

    public function test_page_box_and_margins_come_from_the_pad(): void
    {
        $geometry = $this->geometry(['margins' => ['top' => 22, 'right' => 14, 'bottom' => 18, 'left' => 16]]);

        $this->assertSame('A4 portrait', $geometry->pageSize());
        $this->assertSame('22mm 14mm 18mm 16mm', $geometry->marginCss());
        $this->assertSame(['top' => 22, 'right' => 14, 'bottom' => 18, 'left' => 16], $geometry->margins());
    }

    public function test_paper_dimensions_follow_iso_216_and_the_orientation(): void
    {
        $this->assertSame(210.0, $this->geometry()->paperWidthMm());
        $this->assertSame(297.0, $this->geometry()->paperHeightMm());
        $this->assertSame(148.0, $this->geometry(['paper_size' => 'A5'])->paperWidthMm());
        $this->assertSame(210.0, $this->geometry(['paper_size' => 'A5'])->paperHeightMm());

        $landscape = $this->geometry([], ['orientation' => 'landscape']);
        $this->assertSame(297.0, $landscape->paperWidthMm());
        $this->assertSame(210.0, $landscape->paperHeightMm());
    }

    public function test_the_content_box_is_the_paper_minus_the_margins(): void
    {
        $geometry = $this->geometry(['margins' => ['top' => 20, 'right' => 15, 'bottom' => 20, 'left' => 15]]);

        $this->assertSame(257.0, $geometry->contentHeightMm());     // 297 - 20 - 20
        $this->assertSame(180.0, $geometry->contentWidthMm());      // 210 - 15 - 15
    }

    public function test_the_preprinted_band_is_reserved_only_in_preprinted_mode(): void
    {
        $this->assertSame(0, $this->geometry()->reservedFooterMm());
        $this->assertSame(20, $this->geometry(['preprinted_mode' => true])->reservedFooterMm());
        $this->assertSame(42, $this->geometry(['header_height_mm' => 42])->headerHeightMm());
    }

    public function test_absurd_pad_values_are_clamped_rather_than_trusted(): void
    {
        // The pad designer is user input frozen years ago; a 900mm margin would silently print nothing.
        $geometry = $this->geometry(['margins' => ['top' => 900, 'right' => -5, 'bottom' => 'x', 'left' => 15], 'header_height_mm' => 999, 'font_size_pt' => 400]);

        $this->assertSame(60, $geometry->margins()['top']);
        $this->assertSame(0, $geometry->margins()['right']);
        $this->assertSame(20, $geometry->margins()['bottom']);      // non-numeric falls back to the default
        $this->assertSame(120, $geometry->headerHeightMm());
        $this->assertSame(10.5, $geometry->fontSizePt());
    }

    public function test_the_font_family_is_sanitised_because_it_goes_straight_into_css(): void
    {
        $this->assertSame('Noto Sans Bengali', $this->geometry()->fontFamily());
        $this->assertSame('Inter', $this->geometry(['font_family' => 'Inter'])->fontFamily());
        $this->assertSame('Noto Sans Bengali', $this->geometry(['font_family' => "evil'; } body { display:none } .x {"])->fontFamily());
        $this->assertSame('Noto Sans Bengali', $this->geometry(['font_family' => '{}'])->fontFamily());
    }

    public function test_layout_flags_default_to_true_when_absent(): void
    {
        $this->assertTrue($this->geometry()->flag('icd_codes'));
        $this->assertTrue($this->geometry()->flag('anything_unknown'));
        $this->assertFalse($this->geometry(['layout' => ['flags' => ['icd_codes' => false]]])->flag('icd_codes'));
    }

    public function test_sections_keep_the_doctors_order_and_drop_the_hidden_ones(): void
    {
        $geometry = $this->geometry(['layout' => ['sections' => [
            ['key' => 'rx', 'visible' => true],
            ['key' => 'vitals', 'visible' => false],
            ['key' => 'advice', 'visible' => true],
            ['key' => 'not_a_section', 'visible' => true],
        ]]]);

        $this->assertSame(['rx', 'advice'], $geometry->orderedSections());
        $this->assertFalse($geometry->section('vitals'));
        $this->assertTrue($geometry->section('rx'));
        $this->assertTrue($geometry->section('followup'));          // unlisted sections print
    }

    public function test_an_empty_section_list_falls_back_to_the_full_sheet(): void
    {
        $this->assertSame(PadGeometry::SECTIONS, $this->geometry()->orderedSections());
    }

    public function test_the_rx_font_size_falls_back_to_a_touch_above_the_body(): void
    {
        $this->assertSame(11.0, $this->geometry(['font_size_pt' => 10.5])->rxFontSizePt());
        $this->assertSame(13.0, $this->geometry(['layout' => ['rx_font_size_pt' => 13]])->rxFontSizePt());
        $this->assertSame(11.0, $this->geometry(['layout' => ['rx_font_size_pt' => 99]])->rxFontSizePt());
    }
}
