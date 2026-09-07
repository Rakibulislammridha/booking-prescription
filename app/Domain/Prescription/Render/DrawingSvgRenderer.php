<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Render;

/**
 * PRESCRIPTION.md §4.11 — `drawing_json` → inline SVG for print. Vector, no file access, no network: the annotation
 * is redrawn from the stored geometry, so the printed diagram is sharp at any paper size and identical to what the
 * doctor saw on the tablet. The eight backgrounds mirror `resources/js/panel/lib/prescription/drawingBackgrounds.ts`
 * primitive for primitive (same constants, same coordinates) — the canvas paints them, this paints the same shapes
 * as SVG, and the two must not drift.
 */
final class DrawingSvgRenderer
{
    public const TEMPLATES = ['blank', 'dental_adult', 'dental_child', 'eye_pair', 'skeleton_front', 'body_front_back', 'spine', 'abdomen'];

    private const INK = '#9aa5b1';

    private const FAINT = '#dde3ea';

    /**
     * @param  array<string, mixed>  $json  DrawingJson (§4.11)
     */
    public function render(array $json, ?float $renderWidth = null): string
    {
        $canvas = (array) ($json['canvas'] ?? []);
        $w = max(1.0, (float) ($canvas['w'] ?? 800));
        $h = max(1.0, (float) ($canvas['h'] ?? 600));
        $template = (string) ($canvas['template'] ?? 'blank');
        $template = in_array($template, self::TEMPLATES, true) ? $template : 'blank';

        $body = '<rect x="0" y="0" width="'.self::n($w).'" height="'.self::n($h).'" fill="#ffffff"/>';
        $body .= '<g fill="none" stroke="'.self::INK.'" stroke-width="1.25" font-family="system-ui, sans-serif" font-size="11" text-anchor="middle" dominant-baseline="middle">';
        $body .= $this->background($template, $w, $h);
        $body .= '</g>';
        $body .= $this->strokes((array) ($json['strokes'] ?? []));
        $body .= $this->texts((array) ($json['texts'] ?? []));

        $sizeAttrs = $renderWidth !== null ? ' width="'.self::n($renderWidth).'mm"' : ' width="100%"';

        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 '.self::n($w).' '.self::n($h).'"'.$sizeAttrs.' preserveAspectRatio="xMidYMid meet" role="img">'.$body.'</svg>';
    }

    private function background(string $template, float $w, float $h): string
    {
        return match ($template) {
            'dental_adult' => $this->arches($w, $h, 8, ['1', '2', '3', '4', '5', '6', '7', '8']),
            'dental_child' => $this->arches($w, $h, 5, ['A', 'B', 'C', 'D', 'E']),
            'eye_pair' => $this->eyes($w, $h),
            'skeleton_front' => $this->bodyOutline($w / 2, $h * 0.06, $h * 0.82, 'Anterior'),
            'body_front_back' => $this->bodyOutline($w * 0.3, $h * 0.06, $h * 0.78, 'Front').$this->bodyOutline($w * 0.7, $h * 0.06, $h * 0.78, 'Back'),
            'spine' => $this->spine($w, $h),
            'abdomen' => $this->abdomen($w, $h),
            default => '',
        };
    }

    /** @param  list<string>  $labels */
    private function arches(float $w, float $h, int $perQuadrant, array $labels): string
    {
        $cx = $w / 2;
        $r = min($w, $h) * 0.34;
        $toothR = max(9.0, $r / ($perQuadrant * 1.5));
        $step = (M_PI * 0.78) / ($perQuadrant * 2 - 1);
        $out = '';

        foreach ([true, false] as $upper) {
            $cy = $upper ? $h * 0.3 : $h * 0.72;

            for ($side = 0; $side < 2; $side++) {
                for ($i = 0; $i < $perQuadrant; $i++) {
                    $angle = $step * ($i + 0.5) * 2;
                    $px = $cx + ($side === 0 ? -1 : 1) * sin($angle) * $r;
                    $py = $cy + ($upper ? -1 : 1) * (cos($angle) * $r * 0.5 - $r * 0.5);
                    $out .= $this->tooth($px, $py, $toothR, $labels[$i] ?? (string) ($i + 1));
                }
            }
        }

        $out .= '<path stroke="'.self::FAINT.'" d="M '.self::n($cx).' '.self::n($h * 0.1).' L '.self::n($cx).' '.self::n($h * 0.9)
            .' M '.self::n($w * 0.08).' '.self::n($h * 0.51).' L '.self::n($w * 0.92).' '.self::n($h * 0.51).'"/>';

        return $out;
    }

    private function tooth(float $x, float $y, float $r, string $label): string
    {
        return '<circle cx="'.self::n($x).'" cy="'.self::n($y).'" r="'.self::n($r).'"/>'
            .'<text x="'.self::n($x).'" y="'.self::n($y).'" font-size="'.self::n(round($r)).'" fill="'.self::INK.'" stroke="none">'.self::e($label).'</text>';
    }

    private function eyes(float $w, float $h): string
    {
        $r = min($w / 5, $h / 3);
        $out = '';

        foreach (['RIGHT (OD)', 'LEFT (OS)'] as $i => $label) {
            $cx = $w * ($i === 0 ? 0.28 : 0.72);
            $cy = $h * 0.45;

            foreach ([1.0, 0.55, 0.22] as $scale) {
                $out .= '<circle cx="'.self::n($cx).'" cy="'.self::n($cy).'" r="'.self::n($r * $scale).'"/>';
            }

            $out .= $this->label($cx, $cy + $r + 16, $label);
        }

        return $out;
    }

    private function bodyOutline(float $cx, float $top, float $height, string $label): string
    {
        $u = $height / 8;
        $out = '<circle cx="'.self::n($cx).'" cy="'.self::n($top + $u * 0.7).'" r="'.self::n($u * 0.62).'"/>';
        $out .= '<path d="M '.self::n($cx - $u * 0.95).' '.self::n($top + $u * 1.5)
            .' L '.self::n($cx + $u * 0.95).' '.self::n($top + $u * 1.5)
            .' L '.self::n($cx + $u * 0.75).' '.self::n($top + $u * 4.4)
            .' L '.self::n($cx - $u * 0.75).' '.self::n($top + $u * 4.4).' Z"/>';
        $out .= '<path d="M '.self::n($cx - $u * 0.95).' '.self::n($top + $u * 1.6).' L '.self::n($cx - $u * 1.5).' '.self::n($top + $u * 4.2)
            .' M '.self::n($cx + $u * 0.95).' '.self::n($top + $u * 1.6).' L '.self::n($cx + $u * 1.5).' '.self::n($top + $u * 4.2)
            .' M '.self::n($cx - $u * 0.45).' '.self::n($top + $u * 4.4).' L '.self::n($cx - $u * 0.55).' '.self::n($top + $u * 7.6)
            .' M '.self::n($cx + $u * 0.45).' '.self::n($top + $u * 4.4).' L '.self::n($cx + $u * 0.55).' '.self::n($top + $u * 7.6).'"/>';

        return $out.$this->label($cx, $top + $height + 12, $label);
    }

    private function spine(float $w, float $h): string
    {
        $cx = $w / 2;
        $top = $h * 0.08;
        $step = ($h * 0.8) / 24;
        $index = 0;
        $out = '';

        foreach ([['C', 7], ['T', 12], ['L', 5]] as [$prefix, $count]) {
            for ($i = 1; $i <= $count; $i++) {
                $y = $top + $step * $index;
                $width = 26 + $index * 0.9;
                $out .= '<rect x="'.self::n($cx - $width / 2).'" y="'.self::n($y).'" width="'.self::n($width).'" height="'.self::n($step * 0.72).'"/>';
                $out .= $this->label($cx - $width / 2 - 18, $y + $step * 0.36, $prefix.$i);
                $index++;
            }
        }

        return $out;
    }

    private function abdomen(float $w, float $h): string
    {
        $left = $w * 0.2;
        $top = $h * 0.12;
        $cellW = ($w * 0.6) / 3;
        $cellH = ($h * 0.7) / 3;
        $names = ['RHC', 'Epigastric', 'LHC', 'R lumbar', 'Umbilical', 'L lumbar', 'RIF', 'Hypogastric', 'LIF'];
        $out = '';

        for ($r = 0; $r < 3; $r++) {
            for ($c = 0; $c < 3; $c++) {
                $out .= '<rect x="'.self::n($left + $c * $cellW).'" y="'.self::n($top + $r * $cellH).'" width="'.self::n($cellW).'" height="'.self::n($cellH).'"/>';
                $out .= $this->label($left + $c * $cellW + $cellW / 2, $top + $r * $cellH + 12, $names[$r * 3 + $c], 10.0);
            }
        }

        return $out;
    }

    private function label(float $x, float $y, string $text, float $size = 11.0): string
    {
        return '<text x="'.self::n($x).'" y="'.self::n($y).'" font-size="'.self::n($size).'" fill="'.self::INK.'" stroke="none">'.self::e($text).'</text>';
    }

    /**
     * Pen/marker keep their colour and width; `eraser` strokes paint the paper colour — SVG has no
     * destination-out, and on paper an erased area is simply white.
     *
     * @param  array<int, mixed>  $strokes
     */
    private function strokes(array $strokes): string
    {
        $out = '';

        foreach ($strokes as $stroke) {
            if (! is_array($stroke)) {
                continue;
            }

            $points = array_values(array_filter((array) ($stroke['points'] ?? []), fn ($p) => is_array($p) && count($p) >= 2));

            if ($points === []) {
                continue;
            }

            $tool = (string) ($stroke['tool'] ?? 'pen');
            $colour = $tool === 'eraser' ? '#ffffff' : self::colour($stroke['color'] ?? null);
            $width = max(0.3, min(80.0, (float) ($stroke['width'] ?? 2)));
            $opacity = $tool === 'marker' ? ' stroke-opacity="0.45"' : '';
            $d = [];

            foreach ($points as $point) {
                $d[] = self::n((float) $point[0]).','.self::n((float) $point[1]);
            }

            $out .= '<polyline points="'.implode(' ', $d).'" fill="none" stroke="'.$colour.'" stroke-width="'.self::n($width).'" stroke-linecap="round" stroke-linejoin="round"'.$opacity.'/>';
        }

        return $out;
    }

    /** @param  array<int, mixed>  $texts */
    private function texts(array $texts): string
    {
        $out = '';

        foreach ($texts as $text) {
            if (! is_array($text) || ! isset($text['text'])) {
                continue;
            }

            $size = max(6.0, min(96.0, (float) ($text['size'] ?? 14)));
            $out .= '<text x="'.self::n((float) ($text['x'] ?? 0)).'" y="'.self::n((float) ($text['y'] ?? 0)).'" font-size="'.self::n($size)
                .'" fill="#111827" font-family="\'Noto Sans Bengali\', system-ui, sans-serif" text-anchor="start" dominant-baseline="alphabetic">'.self::e((string) $text['text']).'</text>';
        }

        return $out;
    }

    /** Only literal hex colours from the canvas are echoed into the SVG — never arbitrary attribute text. */
    private static function colour(mixed $value): string
    {
        $colour = is_string($value) ? trim($value) : '';

        return preg_match('/^#[0-9a-fA-F]{3,8}$/', $colour) === 1 ? $colour : '#111827';
    }

    private static function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.') ?: '0';
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
