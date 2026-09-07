<?php

declare(strict_types=1);

namespace Tests\Unit\Prescription\Render;

use App\Domain\Prescription\Render\DrawingSvgRenderer;
use PHPUnit\Framework\TestCase;

/**
 * PRESCRIPTION.md §4.11 — drawing_json → inline SVG. The eight backgrounds mirror the canvas primitives in
 * resources/js/panel/lib/prescription/drawingBackgrounds.ts so the printed diagram matches the screen; the
 * renderer touches no file and no network, which is what lets Browsershot draw it from an HTML string.
 */
final class DrawingSvgRendererTest extends TestCase
{
    /** @param  array<string, mixed>  $json */
    private function render(array $json): string
    {
        return (new DrawingSvgRenderer)->render($json);
    }

    public function test_every_documented_template_renders_its_own_background(): void
    {
        $expected = [
            'blank' => null,
            'dental_adult' => '<circle',
            'dental_child' => '>A</text>',
            'eye_pair' => 'RIGHT (OD)',
            'skeleton_front' => 'Anterior',
            'body_front_back' => 'Front',
            'spine' => '>C1</text>',
            'abdomen' => 'Epigastric',
        ];

        $this->assertSame(array_keys($expected), DrawingSvgRenderer::TEMPLATES);

        foreach ($expected as $template => $marker) {
            $svg = $this->render(['canvas' => ['w' => 800, 'h' => 480, 'template' => $template], 'strokes' => []]);

            $this->assertStringStartsWith('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 800 480"', $svg);
            $this->assertStringEndsWith('</svg>', $svg);

            if ($marker === null) {
                $this->assertStringNotContainsString('<circle', $svg, 'the blank template must draw nothing but paper');
            } else {
                $this->assertStringContainsString($marker, $svg, "template {$template}");
            }
        }
    }

    public function test_strokes_keep_their_geometry_colour_and_tool_semantics(): void
    {
        $svg = $this->render(['canvas' => ['w' => 100, 'h' => 100, 'template' => 'blank'], 'strokes' => [
            ['tool' => 'pen', 'color' => '#b91c1c', 'width' => 3, 'points' => [[10, 20, 1], [30.555, 40, 0.4]]],
            ['tool' => 'marker', 'color' => '#facc15', 'width' => 8, 'points' => [[1, 2, 1], [3, 4, 1]]],
            ['tool' => 'eraser', 'color' => '#000000', 'width' => 12, 'points' => [[5, 5, 1], [6, 6, 1]]],
        ]]);

        $this->assertStringContainsString('points="10,20 30.56,40" fill="none" stroke="#b91c1c" stroke-width="3"', $svg);
        $this->assertStringContainsString('stroke-opacity="0.45"', $svg);           // marker is translucent
        $this->assertStringContainsString('stroke="#ffffff" stroke-width="12"', $svg);  // on paper, erased is white
    }

    public function test_hostile_values_from_the_canvas_never_reach_the_markup(): void
    {
        $svg = $this->render(['canvas' => ['w' => 100, 'h' => 100, 'template' => 'blank'],
            'strokes' => [['tool' => 'pen', 'color' => '"/><script>alert(1)</script>', 'width' => 1e9, 'points' => [[0, 0, 1], [1, 1, 1]]]],
            'texts' => [['x' => 5, 'y' => 5, 'text' => '<script>alert(2)</script>', 'size' => 1e9]],
        ]);

        $this->assertStringNotContainsString('<script>', $svg);
        $this->assertStringContainsString('&lt;script&gt;', $svg);
        $this->assertStringContainsString('stroke="#111827"', $svg);      // the bogus colour fell back
        $this->assertStringContainsString('stroke-width="80"', $svg);      // width clamped
        $this->assertStringContainsString('font-size="96"', $svg);         // text size clamped
    }

    public function test_bangla_annotations_survive_and_carry_a_bangla_capable_font(): void
    {
        $svg = $this->render(['canvas' => ['w' => 200, 'h' => 200, 'template' => 'blank'], 'strokes' => [],
            'texts' => [['x' => 10, 'y' => 20, 'text' => 'ব্যথা এখানে', 'size' => 18]]]);

        $this->assertStringContainsString('ব্যথা এখানে', $svg);
        $this->assertStringContainsString("font-family=\"'Noto Sans Bengali', system-ui, sans-serif\"", $svg);
    }

    public function test_a_junk_document_still_produces_a_valid_svg(): void
    {
        $svg = $this->render(['canvas' => ['w' => 0, 'h' => -5, 'template' => 'not-a-template'], 'strokes' => ['nonsense', ['points' => []]]]);

        $this->assertStringContainsString('viewBox="0 0 1 1"', $svg);
        $this->assertStringEndsWith('</svg>', $svg);
        $this->assertNotFalse(simplexml_load_string($svg), 'the produced SVG is not well-formed XML');
    }

    public function test_the_output_is_well_formed_xml_for_every_template(): void
    {
        foreach (DrawingSvgRenderer::TEMPLATES as $template) {
            $svg = $this->render(['canvas' => ['w' => 640, 'h' => 400, 'template' => $template],
                'strokes' => [['tool' => 'pen', 'color' => '#111827', 'width' => 2, 'points' => [[1, 1, 1], [2, 2, 1]]]],
                'texts' => [['x' => 3, 'y' => 4, 'text' => 'A & B < C', 'size' => 12]]]);

            $this->assertNotFalse(simplexml_load_string($svg), "template {$template} produced invalid XML");
        }
    }
}
