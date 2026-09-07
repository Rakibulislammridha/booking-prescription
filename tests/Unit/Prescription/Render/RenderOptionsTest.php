<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Render;

use App\Domain\Prescription\Render\RenderOptions;
use PHPUnit\Framework\TestCase;

/** PRESCRIPTION.md §7.1 — defaults come from the frozen pad, query parameters override per print, junk is ignored. */
final class RenderOptionsTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function pad(array $overrides = []): array
    {
        return $overrides + [
            'paper_size' => 'A4', 'orientation' => 'portrait', 'letterhead_enabled' => true,
            'preprinted_mode' => false, 'default_language' => 'both',
        ];
    }

    public function test_defaults_are_read_from_the_pad_snapshot(): void
    {
        $options = RenderOptions::fromPad($this->pad(['paper_size' => 'A5', 'orientation' => 'landscape', 'letterhead_enabled' => false, 'preprinted_mode' => true, 'default_language' => 'bn']));

        $this->assertSame('A5', $options->paper);
        $this->assertSame('landscape', $options->orientation);
        $this->assertFalse($options->letterhead);
        $this->assertTrue($options->preprinted);
        $this->assertSame('bn', $options->language);
        $this->assertSame('full', $options->layout);
        $this->assertSame('print', $options->purpose);
    }

    public function test_the_explicit_language_argument_beats_the_pad_default(): void
    {
        $this->assertSame('en', RenderOptions::fromPad($this->pad(), language: 'en')->language);
        $this->assertSame('both', RenderOptions::fromPad($this->pad(), language: 'klingon')->language);
    }

    public function test_query_parameters_override_only_what_they_name(): void
    {
        $options = RenderOptions::fromPad($this->pad())->override(['paper' => 'a5', 'letterhead' => '0', 'lang' => 'bn']);

        $this->assertSame('A5', $options->paper);            // case-insensitive
        $this->assertFalse($options->letterhead);
        $this->assertSame('bn', $options->language);
        $this->assertFalse($options->preprinted);            // untouched
        $this->assertSame('portrait', $options->orientation);
    }

    public function test_a_mistyped_parameter_is_ignored_rather_than_thrown(): void
    {
        // A doctor mid-consultation must still get their prescription, not a 500 from a bad bookmark.
        $options = RenderOptions::fromPad($this->pad())->override(['paper' => 'A3', 'layout' => 'wat', 'lang' => 'de']);

        $this->assertSame('A4', $options->paper);
        $this->assertSame('full', $options->layout);
        $this->assertSame('both', $options->language);
    }

    public function test_language_predicates_drive_which_renderings_print(): void
    {
        $both = RenderOptions::fromPad($this->pad(['default_language' => 'both']));
        $this->assertTrue($both->bn());
        $this->assertTrue($both->en());
        $this->assertTrue($both->both());
        $this->assertSame('bn', $both->primary());

        $en = RenderOptions::fromPad($this->pad(['default_language' => 'en']));
        $this->assertFalse($en->bn());
        $this->assertTrue($en->en());
        $this->assertSame('en', $en->primary());

        $bn = RenderOptions::fromPad($this->pad(['default_language' => 'bn']));
        $this->assertTrue($bn->bn());
        $this->assertFalse($bn->en());
    }

    public function test_only_the_three_documented_watermarks_are_accepted(): void
    {
        $base = RenderOptions::fromPad($this->pad());

        $this->assertSame('DRAFT', $base->withWatermark('draft')->watermark);
        $this->assertSame('VOID', $base->withWatermark('VOID')->watermark);
        $this->assertNull($base->withWatermark('CONFIDENTIAL')->watermark);
        $this->assertNull($base->withWatermark(null)->watermark);
    }

    public function test_options_are_immutable(): void
    {
        $base = RenderOptions::fromPad($this->pad());
        $derived = $base->override(['paper' => 'A5'])->withWatermark('COPY')->withPurpose('pdf');

        $this->assertSame('A4', $base->paper);
        $this->assertNull($base->watermark);
        $this->assertSame('print', $base->purpose);
        $this->assertSame('A5', $derived->paper);
        $this->assertSame('pdf', $derived->purpose);
    }
}
